<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Reference verifier for the published lists, exactly as clients must implement it (concept 8.2, 8.3):
 *
 *  1. keys.json is verified against the pinned root public key(s);
 *  2. servers.json / search.json are verified against an active list key from that key set;
 *  3. the serial must be higher than the last one the client accepted;
 *  4. expiry is reported, not enforced: clients keep using an expired list but mark it old.
 */
final class ListVerifier
{
    private const MAX_ENVELOPE_BYTES = 2_000_000;
    private const MAX_SIGNATURES = 8;
    private const CLOCK_SKEW_SECONDS = 300;

    /**
     * @param array<string, string> $rootKeys kid => raw Ed25519 public key (pinned)
     */
    public function __construct(private readonly array $rootKeys)
    {
    }

    /**
     * @return array{payload: array<string, mixed>, listKeys: array<string, string>}
     */
    public function verifyKeySet(string $envelopeJson, ?int $lastSerial, \DateTimeImmutable $now): array
    {
        $payload = $this->verifyEnvelope($envelopeJson, $this->rootKeys, 'keys', $lastSerial, $now);

        $listKeys = [];
        $keys = $payload['keys'] ?? null;
        if (!\is_array($keys) || !array_is_list($keys)) {
            throw new VerificationException('Key set has no keys.');
        }
        foreach ($keys as $key) {
            if (!\is_array($key) || !\is_string($key['kid'] ?? null) || !\is_string($key['public_key'] ?? null)) {
                throw new VerificationException('Malformed key entry.');
            }
            if ('Ed25519' !== ($key['alg'] ?? null) || 'active' !== ($key['status'] ?? null)) {
                continue;
            }
            // Trust a key only while its not_after, if present, is provably in the future.
            if (isset($key['not_after'])) {
                try {
                    $notAfter = \is_string($key['not_after']) ? new \DateTimeImmutable($key['not_after']) : null;
                } catch (\Exception) {
                    $notAfter = null;
                }
                if (null === $notAfter || $notAfter < $now) {
                    continue;
                }
            }
            try {
                $listKeys[$key['kid']] = Ed25519::decodePublicKey($key['public_key']);
            } catch (\InvalidArgumentException) {
                throw new VerificationException('Key set contains an invalid public key.');
            }
        }
        if ([] === $listKeys) {
            throw new VerificationException('Key set has no active list key.');
        }

        return ['payload' => $payload, 'listKeys' => $listKeys];
    }

    /**
     * @param array<string, string> $listKeys kid => raw public key, from verifyKeySet()
     *
     * @return array{payload: array<string, mixed>, expired: bool, tooOld: bool}
     */
    public function verifyList(string $envelopeJson, array $listKeys, string $expectedList, ?int $lastSerial, \DateTimeImmutable $now): array
    {
        $payload = $this->verifyEnvelope($envelopeJson, $listKeys, $expectedList, $lastSerial, $now);
        $expires = new \DateTimeImmutable((string) $payload['expires_at']);
        $issued = new \DateTimeImmutable((string) $payload['issued_at']);

        return [
            'payload' => $payload,
            'expired' => $expires < $now,
            // After 90 days apps stop recommending servers from the list (concept 8.2).
            'tooOld' => $issued->modify('+90 days') < $now,
        ];
    }

    /**
     * Checks only the envelope and its signature (no list name, serial or validity), for other
     * signed documents such as the daily audit chain head.
     *
     * @param array<string, string> $trustedKeys kid => raw Ed25519 public key
     *
     * @return array<string, mixed> the payload
     */
    public function verifySignedDocument(string $json, array $trustedKeys): array
    {
        if (\strlen($json) > self::MAX_ENVELOPE_BYTES) {
            throw new VerificationException('Envelope too large.');
        }
        try {
            $envelope = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new VerificationException('Envelope is not valid JSON.');
        }
        if (!\is_array($envelope) || !\is_string($envelope['payload'] ?? null) || !\is_array($envelope['signatures'] ?? null)) {
            throw new VerificationException('Malformed envelope.');
        }
        $signatures = $envelope['signatures'];
        if ([] === $signatures || \count($signatures) > self::MAX_SIGNATURES || !array_is_list($signatures)) {
            throw new VerificationException('Bad signature count.');
        }

        try {
            $payloadBytes = Base64Url::decode($envelope['payload']);
        } catch (\InvalidArgumentException) {
            throw new VerificationException('Payload is not base64url.');
        }

        $valid = false;
        foreach ($signatures as $signature) {
            if (!\is_array($signature) || !\is_string($signature['kid'] ?? null) || !\is_string($signature['sig'] ?? null)) {
                throw new VerificationException('Malformed signature entry.');
            }
            if ('Ed25519' !== ($signature['alg'] ?? null) || !isset($trustedKeys[$signature['kid']])) {
                continue; // unknown or revoked key: ignore, another signature may still be valid
            }
            try {
                $sig = Base64Url::decode($signature['sig']);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (Ed25519::verify($sig, $payloadBytes, $trustedKeys[$signature['kid']])) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new VerificationException('No valid signature from a trusted key.');
        }

        try {
            $payload = json_decode($payloadBytes, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new VerificationException('Payload is not valid JSON.');
        }
        if (!\is_array($payload)) {
            throw new VerificationException('Payload is not an object.');
        }

        return $payload;
    }

    /**
     * @param array<string, string> $trustedKeys
     *
     * @return array<string, mixed>
     */
    private function verifyEnvelope(string $json, array $trustedKeys, string $expectedList, ?int $lastSerial, \DateTimeImmutable $now): array
    {
        $payload = $this->verifySignedDocument($json, $trustedKeys);
        if (($payload['list'] ?? null) !== $expectedList) {
            throw new VerificationException(\sprintf('Expected list "%s".', $expectedList));
        }
        if (1 !== ($payload['version'] ?? null)) {
            throw new VerificationException('Unsupported list version.');
        }
        if (!\is_int($payload['serial'] ?? null) || $payload['serial'] < 1) {
            throw new VerificationException('Missing serial.');
        }
        if (null !== $lastSerial && $payload['serial'] <= $lastSerial) {
            throw new VerificationException('Serial is not higher than the last accepted one (replay).');
        }
        if (!\is_string($payload['issued_at'] ?? null) || !\is_string($payload['expires_at'] ?? null)) {
            throw new VerificationException('Missing timestamps.');
        }
        try {
            $issued = new \DateTimeImmutable($payload['issued_at']);
            new \DateTimeImmutable($payload['expires_at']);
        } catch (\Exception) {
            throw new VerificationException('Invalid timestamps.');
        }
        if ($issued->getTimestamp() > $now->getTimestamp() + self::CLOCK_SKEW_SECONDS) {
            throw new VerificationException('List issued in the future.');
        }

        return $payload;
    }
}
