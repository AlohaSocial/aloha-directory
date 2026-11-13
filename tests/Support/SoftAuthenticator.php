<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Crypto\Base64Url;

/**
 * A software passkey for the tests: an ES256 key pair, "none" attestation, discoverable, with
 * user verification. It produces exactly the JSON a browser's PublicKeyCredential.toJSON() sends.
 */
final class SoftAuthenticator
{
    private \OpenSSLAsymmetricKey $key;
    public string $credentialId;
    private ?string $userHandle = null;
    private int $counter = 0;

    public function __construct(public string $origin = 'https://directory.test-aloha.social')
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        \assert($key instanceof \OpenSSLAsymmetricKey);
        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * @param string $optionsJson PublicKeyCredentialCreationOptions as JSON (data-passkey-create)
     */
    public function create(string $optionsJson): string
    {
        $options = json_decode($optionsJson, true, 32, \JSON_THROW_ON_ERROR);
        $rpId = (string) $options['rp']['id'];
        $this->userHandle = Base64Url::decode((string) $options['user']['id']);
        $clientData = (string) json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $this->origin, 'crossOrigin' => false], \JSON_UNESCAPED_SLASHES);

        $details = openssl_pkey_get_details($this->key);
        \assert(\is_array($details));
        $cose = self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes(str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT)), -3 => new CborBytes(str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT))]);
        $authData = hash('sha256', $rpId, true)
            .\chr(0x01 | 0x04 | 0x40) // user present, user verified, attested credential data
            .pack('N', $this->counter)
            .str_repeat("\0", 16) // AAGUID
            .pack('n', \strlen($this->credentialId)).$this->credentialId
            .$cose;
        $attestationObject = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);

        return (string) json_encode([
            'id' => Base64Url::encode($this->credentialId),
            'rawId' => Base64Url::encode($this->credentialId),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => new \stdClass(),
            'response' => [
                'clientDataJSON' => Base64Url::encode($clientData),
                'attestationObject' => Base64Url::encode($attestationObject),
                'transports' => ['internal'],
            ],
        ], \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param string $optionsJson PublicKeyCredentialRequestOptions as JSON (data-passkey-get)
     */
    public function get(string $optionsJson, ?string $origin = null): string
    {
        $options = json_decode($optionsJson, true, 32, \JSON_THROW_ON_ERROR);
        $clientData = (string) json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin ?? $this->origin, 'crossOrigin' => false], \JSON_UNESCAPED_SLASHES);
        $authData = hash('sha256', (string) $options['rpId'], true).\chr(0x01 | 0x04).pack('N', ++$this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, \OPENSSL_ALGO_SHA256);

        return (string) json_encode([
            'id' => Base64Url::encode($this->credentialId),
            'rawId' => Base64Url::encode($this->credentialId),
            'type' => 'public-key',
            'authenticatorAttachment' => 'platform',
            'clientExtensionResults' => new \stdClass(),
            'response' => [
                'clientDataJSON' => Base64Url::encode($clientData),
                'authenticatorData' => Base64Url::encode($authData),
                'signature' => Base64Url::encode($signature),
                'userHandle' => null === $this->userHandle ? null : Base64Url::encode($this->userHandle),
            ],
        ], \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Minimal CBOR encoder (RFC 8949) for maps, integers, text and byte strings.
     */
    private static function cbor(mixed $value): string
    {
        return match (true) {
            $value instanceof CborBytes => self::head(2, \strlen($value->bytes)).$value->bytes,
            \is_int($value) && $value >= 0 => self::head(0, $value),
            \is_int($value) => self::head(1, -1 - $value),
            \is_string($value) => self::head(3, \strlen($value)).$value,
            \is_array($value) => self::head(5, \count($value)).implode('', array_map(static fn ($k, $v): string => self::cbor($k).self::cbor($v), array_keys($value), $value)),
            default => throw new \LogicException('Unsupported CBOR value.'),
        };
    }

    private static function head(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => \chr(($major << 5) | $length),
            $length < 0x100 => \chr(($major << 5) | 24).\chr($length),
            $length < 0x10000 => \chr(($major << 5) | 25).pack('n', $length),
            default => \chr(($major << 5) | 26).pack('N', $length),
        };
    }
}
