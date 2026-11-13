<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * The signed list envelope of concept 8.2:
 * {"payload": base64url(canonical JSON), "signatures": [{"kid","alg","sig"}]}.
 */
final class SignedEnvelope
{
    /**
     * @param array<string, mixed>                                $payload
     * @param callable(string): list<array{kid: string, alg: string, sig: string}> $sign
     *
     * @return array{json: string, payload: string}
     */
    public static function build(array $payload, callable $sign): array
    {
        $canonical = JsonCanonicalizer::canonicalize($payload);
        $signatures = $sign($canonical);
        if ([] === $signatures) {
            throw new \RuntimeException('A list must carry at least one signature.');
        }
        $envelope = ['payload' => Base64Url::encode($canonical), 'signatures' => $signatures];

        return [
            'json' => json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT)."\n",
            'payload' => $canonical,
        ];
    }
}
