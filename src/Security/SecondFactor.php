<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Curator;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The second factor of the console: a passkey, or one of the single-use recovery codes when the
 * device is lost. Always checked after the password, so nobody without the password can use up
 * recovery codes or probe passkeys.
 */
final class SecondFactor
{
    public function __construct(
        private readonly Passkeys $passkeys,
        private readonly RecoveryCodes $recoveryCodes,
    ) {
    }

    public function check(Curator $curator, SessionInterface $session, string $purpose, string $passkeyJson, string $recoveryCode): bool
    {
        $recoveryCode = trim($recoveryCode);
        if ('' !== $recoveryCode) {
            return RecoveryCodes::looksLikeRecoveryCode($recoveryCode) && $this->recoveryCodes->consume($curator, $recoveryCode);
        }

        return $this->passkeys->verify($session, $purpose, $passkeyJson, $curator);
    }
}
