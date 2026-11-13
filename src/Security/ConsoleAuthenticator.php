<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Curator;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * One-step sign-in with e-mail, password and passkey (or a recovery code). There is never a
 * half-authenticated session, and the passkey is only checked once the password is correct
 * (SecondFactorListener), so an attacker without the password cannot burn recovery codes.
 */
final class ConsoleAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'console_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = mb_substr(trim((string) $request->request->get('email', '')), 0, 180);
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge(mb_strtolower($email)),
            new PasswordCredentials(mb_substr((string) $request->request->get('password', ''), 0, 4096)),
            [
                new CsrfTokenBadge('console_login', (string) $request->request->get('_csrf_token', '')),
                new SecondFactorBadge(
                    mb_substr((string) $request->request->get('passkey', ''), 0, 16_384),
                    mb_substr((string) $request->request->get('recovery_code', ''), 0, 32),
                ),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $curator = $token->getUser();
        if ($curator instanceof Curator) {
            $curator->lastLoginAt = $this->clock->now();
            $this->audit->curator($curator, 'curator.login');
            $this->em->flush();
        }
        $session = $request->getSession();
        $session->set(SessionTimeoutSubscriber::LOGIN_AT, $this->clock->now()->getTimestamp());
        $session->set(SessionTimeoutSubscriber::LAST_ACTIVITY, $this->clock->now()->getTimestamp());

        $target = $this->getTargetPath($session, $firewallName);
        $this->removeTargetPath($session, $firewallName);
        if (null !== $target && str_starts_with((string) parse_url($target, \PHP_URL_PATH), '/console')) {
            return new RedirectResponse($target);
        }

        return new RedirectResponse($this->urls->generate('console_queue'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $this->logger->notice('Curator sign-in failed.', ['reason' => $exception->getMessageKey()]);

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate(self::LOGIN_ROUTE);
    }
}
