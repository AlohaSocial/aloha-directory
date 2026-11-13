<?php

declare(strict_types=1);

namespace App\Http\Signature;

use App\Crypto\Ed25519;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies RFC 9421 HTTP Message Signatures (Ed25519) under the directory profile.
 *
 * The @authority is taken from configuration, never from the request, so a signature made for
 * another host (including a reverse proxy's view of it) can never verify here.
 */
final class MessageSignatureVerifier
{
    public function __construct(
        private readonly string $authority,
        private readonly ClockInterface $clock,
        private readonly ReplayGuard $replayGuard,
    ) {
    }

    /**
     * @param callable(string $keyId): ?string $resolveKey returns the raw public key for a key id, or null
     *
     * @return string the key id that verified
     */
    public function verify(Request $request, string $body, callable $resolveKey): string
    {
        $inputHeader = $request->headers->get('Signature-Input');
        $signatureHeader = $request->headers->get('Signature');
        if (null === $inputHeader || null === $signatureHeader) {
            throw new SignatureException('missing_signature', 'The request is not signed.');
        }

        try {
            $inputs = StructuredFields::parseDictionary($inputHeader);
            $signatures = StructuredFields::parseDictionary($signatureHeader);
        } catch (\InvalidArgumentException $e) {
            throw new SignatureException('malformed_signature', 'Malformed signature headers: '.$e->getMessage());
        }
        if (1 !== \count($inputs) || 1 !== \count($signatures)) {
            throw new SignatureException('malformed_signature', 'Exactly one signature is expected.');
        }
        $label = array_key_first($inputs);
        if (!isset($signatures[$label])) {
            throw new SignatureException('malformed_signature', 'Signature and Signature-Input labels differ.');
        }

        $input = $inputs[$label];
        $signature = $signatures[$label]['value'];
        if (!\is_array($input['value']) || !$signature instanceof SfBytes) {
            throw new SignatureException('malformed_signature', 'Malformed signature members.');
        }

        $components = [];
        $items = [];
        foreach ($input['value'] as $item) {
            if (!\is_string($item['value']) || [] !== $item['params']) {
                throw new SignatureException('malformed_signature', 'Component identifiers must be plain strings.');
            }
            if (!\in_array($item['value'], SignatureProfile::ALLOWED_COMPONENTS, true)) {
                throw new SignatureException('unsupported_component', \sprintf('Component "%s" is not supported.', $item['value']));
            }
            if (\in_array($item['value'], $components, true)) {
                throw new SignatureException('malformed_signature', 'Duplicate component.');
            }
            $components[] = $item['value'];
            $items[] = ['value' => $item['value'], 'params' => []];
        }

        $params = $input['params'];
        $this->checkParams($params);
        $this->checkCoverage($components, $request, $body);

        $keyId = $params['keyid'];
        $publicKey = $resolveKey($keyId);
        if (null === $publicKey) {
            throw new SignatureException('unknown_key', 'Unknown key id.');
        }

        $base = $this->signatureBase($components, $items, $params, $request);
        if (!Ed25519::verify($signature->bytes, $base, $publicKey)) {
            throw new SignatureException('bad_signature', 'Signature does not verify.');
        }

        if (\in_array('content-digest', $components, true)) {
            $this->verifyContentDigest((string) $request->headers->get('Content-Digest'), $body);
        }

        $expires = (new \DateTimeImmutable())->setTimestamp($params['created'] + SignatureProfile::REPLAY_WINDOW_SECONDS);
        if (!$this->replayGuard->firstSeen(hash('sha256', $signature->bytes), $expires)) {
            throw new SignatureException('replayed_signature', 'This signature was already used.');
        }

        return $keyId;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function checkParams(array $params): void
    {
        $now = $this->clock->now()->getTimestamp();
        if (!\is_int($params['created'] ?? null)) {
            throw new SignatureException('malformed_signature', 'The "created" parameter is required.');
        }
        if (abs($now - $params['created']) > SignatureProfile::MAX_AGE_SECONDS) {
            throw new SignatureException('signature_expired', 'The signature is too old or from the future; check the server clock.');
        }
        if (isset($params['expires']) && (!\is_int($params['expires']) || $params['expires'] < $now)) {
            throw new SignatureException('signature_expired', 'The signature has expired.');
        }
        if (!\is_string($params['keyid'] ?? null) || '' === $params['keyid'] || \strlen($params['keyid']) > 128) {
            throw new SignatureException('malformed_signature', 'The "keyid" parameter is required.');
        }
        if (isset($params['alg']) && SignatureProfile::ALG !== $params['alg']) {
            throw new SignatureException('unsupported_algorithm', 'Only ed25519 is accepted.');
        }
        if (SignatureProfile::TAG !== ($params['tag'] ?? null)) {
            throw new SignatureException('malformed_signature', \sprintf('The "tag" parameter must be "%s".', SignatureProfile::TAG));
        }
        if (isset($params['nonce']) && (!\is_string($params['nonce']) || \strlen($params['nonce']) > 128)) {
            throw new SignatureException('malformed_signature', 'Invalid nonce.');
        }
    }

    /**
     * @param list<string> $components
     */
    private function checkCoverage(array $components, Request $request, string $body): void
    {
        $has = static fn (string $c): bool => \in_array($c, $components, true);
        $target = $has('@target-uri');

        if (!$has('@method')) {
            throw new SignatureException('insufficient_coverage', 'The signature must cover "@method".');
        }
        if (!$target && (!$has('@authority') || !$has('@path'))) {
            throw new SignatureException('insufficient_coverage', 'The signature must cover "@authority" and "@path" (or "@target-uri").');
        }
        if ('' !== $this->rawQuery($request) && !$target && !$has('@query')) {
            throw new SignatureException('insufficient_coverage', 'The signature must cover "@query".');
        }
        if ('' !== $body && !$has('content-digest')) {
            throw new SignatureException('insufficient_coverage', 'A request with a body must cover "content-digest".');
        }
    }

    /**
     * @param list<string>                                         $components
     * @param list<array{value: string, params: array<string, mixed>}> $items
     * @param array<string, mixed>                                 $params
     */
    private function signatureBase(array $components, array $items, array $params, Request $request): string
    {
        $lines = [];
        foreach ($components as $component) {
            $lines[] = '"'.$component.'": '.$this->componentValue($component, $request);
        }
        $lines[] = '"@signature-params": '.StructuredFields::serializeInnerList($items, $params);

        return implode("\n", $lines);
    }

    private function componentValue(string $component, Request $request): string
    {
        return match ($component) {
            '@method' => strtoupper($request->getMethod()),
            '@authority' => strtolower($this->authority),
            '@path' => $this->rawPath($request),
            '@query' => '?'.$this->rawQuery($request),
            '@target-uri' => 'https://'.strtolower($this->authority).$this->rawPath($request).('' !== $this->rawQuery($request) ? '?'.$this->rawQuery($request) : ''),
            default => $this->headerValue($request, $component),
        };
    }

    private function headerValue(Request $request, string $name): string
    {
        $values = $request->headers->all($name);
        if ([] === $values) {
            throw new SignatureException('insufficient_coverage', \sprintf('Covered header "%s" is missing.', $name));
        }

        return implode(', ', array_map(static fn (?string $v): string => trim((string) $v), $values));
    }

    private function rawPath(Request $request): string
    {
        $uri = (string) $request->server->get('REQUEST_URI', $request->getPathInfo());
        $path = explode('?', $uri, 2)[0];
        if (1 === preg_match('#\A[a-z][a-z0-9+.-]*://[^/]*#i', $path, $m)) {
            $path = substr($path, \strlen($m[0])); // absolute-form request target
        }

        return '' === $path ? '/' : $path;
    }

    private function rawQuery(Request $request): string
    {
        $uri = (string) $request->server->get('REQUEST_URI', '');
        $parts = explode('?', $uri, 2);

        return $parts[1] ?? '';
    }

    private function verifyContentDigest(string $header, string $body): void
    {
        try {
            $digests = StructuredFields::parseDictionary($header);
        } catch (\InvalidArgumentException) {
            throw new SignatureException('bad_digest', 'Malformed Content-Digest.');
        }
        $algorithms = ['sha-256' => 'sha256', 'sha-512' => 'sha512'];
        $checked = 0;
        foreach ($algorithms as $name => $algo) {
            if (!isset($digests[$name])) {
                continue;
            }
            $value = $digests[$name]['value'];
            if (!$value instanceof SfBytes || !hash_equals(hash($algo, $body, true), $value->bytes)) {
                throw new SignatureException('bad_digest', 'Content-Digest does not match the body.');
            }
            ++$checked;
        }
        if (0 === $checked) {
            throw new SignatureException('bad_digest', 'Content-Digest must include sha-256 or sha-512.');
        }
    }
}
