<?php

declare(strict_types=1);

namespace App\Http\Signature;

use App\Crypto\Ed25519;

/**
 * Reference implementation of the client side of the directory signature profile. Used by the tests
 * and by `app:dev:server` to simulate an Aloha server; the Aloha server app implements the same.
 */
final class MessageSigner
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $publicKey,
    ) {
    }

    /**
     * @return array<string, string> headers to add to the request
     */
    public function sign(string $method, string $authority, string $pathAndQuery, string $body, ?int $created = null): array
    {
        $headers = [];
        $components = ['@method', '@authority', '@path'];
        $values = [
            '@method' => strtoupper($method),
            '@authority' => strtolower($authority),
            '@path' => explode('?', $pathAndQuery, 2)[0],
        ];
        if (str_contains($pathAndQuery, '?')) {
            $components[] = '@query';
            $values['@query'] = '?'.explode('?', $pathAndQuery, 2)[1];
        }
        if ('' !== $body) {
            $headers['Content-Digest'] = 'sha-256=:'.base64_encode(hash('sha256', $body, true)).':';
            $components[] = 'content-digest';
            $values['content-digest'] = $headers['Content-Digest'];
        }

        $items = array_map(static fn (string $c): array => ['value' => $c, 'params' => []], $components);
        $params = [
            'created' => $created ?? time(),
            'keyid' => Ed25519::fingerprint($this->publicKey),
            'alg' => SignatureProfile::ALG,
            'tag' => SignatureProfile::TAG,
            // Ed25519 is deterministic: the nonce keeps two identical requests in the same second apart.
            'nonce' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        ];
        $serializedParams = StructuredFields::serializeInnerList($items, $params);

        $lines = [];
        foreach ($values as $component => $value) {
            $lines[] = '"'.$component.'": '.$value;
        }
        $lines[] = '"@signature-params": '.$serializedParams;

        $signature = Ed25519::sign(implode("\n", $lines), $this->secretKey);
        $headers['Signature-Input'] = 'dir='.$serializedParams;
        $headers['Signature'] = 'dir=:'.base64_encode($signature).':';

        return $headers;
    }
}
