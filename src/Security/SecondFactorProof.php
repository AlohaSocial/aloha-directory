<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * What a form sent as second factor: a passkey assertion for the challenge of $purpose, or a
 * recovery code.
 */
final readonly class SecondFactorProof
{
    public function __construct(
        public SessionInterface $session,
        public string $purpose,
        public string $passkeyJson,
        public string $recoveryCode,
    ) {
    }

    public static function fromRequest(Request $request, string $purpose, bool $allowRecoveryCode = true): self
    {
        return new self(
            $request->getSession(),
            $purpose,
            mb_substr((string) $request->request->get('passkey', ''), 0, 16_384),
            $allowRecoveryCode ? mb_substr((string) $request->request->get('recovery_code', ''), 0, 32) : '',
        );
    }
}
