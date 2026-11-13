<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

final class SecondFactorBadge implements BadgeInterface
{
    private bool $resolved = false;

    public function __construct(
        public readonly string $passkeyJson,
        public readonly string $recoveryCode,
    ) {
    }

    public function markResolved(): void
    {
        $this->resolved = true;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
