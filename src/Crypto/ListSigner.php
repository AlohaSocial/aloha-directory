<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Signs list payloads. The default implementation reads seeds from files; an HSM- or
 * KMS-backed implementation only has to implement this interface (concept 8.3).
 */
interface ListSigner
{
    /**
     * @return list<array{kid: string, alg: string, sig: string}> one entry per active list key
     */
    public function sign(string $payload): array;

    /**
     * @return array<string, string> kid => raw public key
     */
    public function publicKeys(): array;
}
