<?php

declare(strict_types=1);

namespace App\Http\Signature;

interface ReplayGuard
{
    /**
     * Records the fingerprint and returns false when it was already seen (a replay).
     */
    public function firstSeen(string $fingerprint, \DateTimeImmutable $expiresAt): bool;
}
