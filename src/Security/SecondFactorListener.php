<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Curator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Checks the passkey (or recovery code) of a sign-in. Runs after the password check (priority
 * below CheckCredentialsListener's 0), so a wrong password never reaches it.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: -64)]
final class SecondFactorListener
{
    public const PURPOSE = 'login';

    public function __construct(
        private readonly SecondFactor $secondFactor,
        private readonly RequestStack $requests,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(SecondFactorBadge::class)) {
            return;
        }
        /** @var SecondFactorBadge $badge */
        $badge = $passport->getBadge(SecondFactorBadge::class);
        $user = $passport->getUser();
        $session = $this->requests->getCurrentRequest()?->getSession();
        if (!$user instanceof Curator || null === $session || !$this->secondFactor->check($user, $session, self::PURPOSE, $badge->passkeyJson, $badge->recoveryCode)) {
            throw new BadCredentialsException('Invalid second factor.');
        }
        $badge->markResolved();
    }
}
