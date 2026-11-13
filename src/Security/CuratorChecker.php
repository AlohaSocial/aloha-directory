<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Curator;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class CuratorChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        // Same message as a wrong password: the sign-in form never reveals which accounts exist.
        if (!$user instanceof Curator || !$user->active || null === $user->webauthnUserHandle) {
            throw new BadCredentialsException('Account not usable.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
