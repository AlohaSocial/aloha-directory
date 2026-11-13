<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\DirectorySettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Short console sessions (concept 13.4): an idle timeout and an absolute lifetime.
 * Runs after the firewall has loaded the session (priority below 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class SessionTimeoutSubscriber
{
    public const LOGIN_AT = '_aloha_login_at';
    public const LAST_ACTIVITY = '_aloha_last_activity';

    public function __construct(
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/console') || !$request->hasPreviousSession()) {
            return;
        }
        $session = $request->getSession();
        $loginAt = $session->get(self::LOGIN_AT);
        if (null === $loginAt) {
            return; // not signed in
        }
        $now = $this->clock->now()->getTimestamp();
        $idle = $now - (int) $session->get(self::LAST_ACTIVITY, 0);
        $age = $now - (int) $loginAt;
        if ($idle > 60 * $this->settings->int('session_idle_minutes') || $age > 3600 * $this->settings->int('session_absolute_hours')) {
            $session->invalidate();
            $event->setResponse(new RedirectResponse($this->urls->generate('console_login', ['expired' => 1])));

            return;
        }
        $session->set(self::LAST_ACTIVITY, $now);
    }
}
