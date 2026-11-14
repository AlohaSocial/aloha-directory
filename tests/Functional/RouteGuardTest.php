<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditLogEntry;
use App\Enum\ServerState;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Walks every route the application has, so a new route is guarded by these tests without
 * anyone having to remember to add it here.
 */
final class RouteGuardTest extends FunctionalTestCase
{
    private const BASE = 'https://directory.test-aloha.social';

    /** Console routes that work without a session. */
    private const PUBLIC_CONSOLE_ROUTES = ['console_login', 'console_invite'];

    /** Routes the tests below do not know yet. A new route fails testEveryRouteIsKnown until it is sorted in. */
    private const KNOWN_PUBLIC_ROUTES = ['metrics', 'healthz', 'report'];

    public function testEveryRouteIsKnown(): void
    {
        foreach ($this->routes() as $name => $route) {
            $path = $route->getPath();
            self::assertTrue(
                str_starts_with($path, '/console') || str_starts_with($path, '/api/') || \in_array($name, self::KNOWN_PUBLIC_ROUTES, true),
                \sprintf('Route "%s" (%s) is not covered by RouteGuardTest; add it to a group.', $name, $path),
            );
        }
    }

    public function testAnonymousVisitorsCannotReachTheConsole(): void
    {
        $this->fixtures();
        $auditRows = $this->auditRows();

        foreach ($this->consoleRoutes() as $name => $route) {
            foreach ($this->methods($route) as $method) {
                $this->client->request($method, self::BASE.$this->url($route), ['_csrf_token' => 'x', 'concern' => 'x', 'email' => 'x@example.org']);
                $response = $this->client->getResponse();
                self::assertTrue(
                    $response->isRedirect(self::BASE.'/console/login') || $response->isRedirect('/console/login') || 401 === $response->getStatusCode(),
                    \sprintf('%s %s (%s) answered %d instead of sending the visitor to the login.', $method, $route->getPath(), $name, $response->getStatusCode()),
                );
            }
        }

        self::assertSame($auditRows, $this->auditRows(), 'An anonymous request changed something.');
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state);
    }

    public function testEveryConsoleFormNeedsAValidCsrfToken(): void
    {
        $this->fixtures();
        $this->consoleLogin('alice@nextcloud.test');
        self::assertResponseRedirects(self::BASE.'/console');
        $auditRows = $this->auditRows();

        foreach ($this->consoleRoutes() as $name => $route) {
            if (!\in_array('POST', $this->methods($route), true) || 'console_logout' === $name) {
                continue;
            }
            foreach (['forged', null] as $token) {
                $fields = ['concern' => 'Forged.', 'note' => 'Forged.', 'body' => 'Forged.', 'reason' => 'Forged.', 'domain' => 'forged.example.org',
                    'email' => 'forged@example.org', 'name' => 'Forged', 'form' => 'recovery', 'password' => 'correct horse battery staple', 'action' => 'pause'];
                if (null !== $token) {
                    $fields['_csrf_token'] = $token;
                }
                $this->client->request('POST', self::BASE.$this->url($route), $fields);
                self::assertSame(403, $this->client->getResponse()->getStatusCode(), \sprintf('POST %s (%s) without a valid CSRF token', $route->getPath(), $name));
            }
        }

        self::assertSame($auditRows, $this->auditRows(), 'A forged form changed something.');
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state);
        // The session survived all of this.
        $this->client->request('GET', self::BASE.'/console');
        self::assertResponseIsSuccessful();
    }

    public function testApiNeverAnswersWithAServerError(): void
    {
        $this->fixtures();
        $auditRows = $this->auditRows();

        $bodies = ['', 'not json', '{', '[]', '"a string"', '{"domain": ["array"]}', '{"domain": "social.example.org", "text": 12}', str_repeat('[', 2000).str_repeat(']', 2000)];
        foreach ($this->routes() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            foreach ($this->methods($route) as $method) {
                foreach ($bodies as $body) {
                    $this->client->request($method, self::BASE.$this->url($route), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
                    $response = $this->client->getResponse();
                    $status = $response->getStatusCode();
                    $what = \sprintf('%s %s (%s) with body %s', $method, $route->getPath(), $name, json_encode(mb_substr($body, 0, 40)));
                    self::assertTrue($status >= 400 && $status < 500, $what.' answered '.$status);
                    self::assertJson((string) $response->getContent(), $what);
                    self::assertStringNotContainsString('Stack trace', (string) $response->getContent(), $what);
                }
            }
        }

        self::assertSame($auditRows, $this->auditRows(), 'An unsigned or malformed API request changed something.');
    }

    /**
     * A listed server, two curators, a complaint, a block list entry and a conflict of interest, so
     * that the ids the routes take (1) exist.
     */
    private function fixtures(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $this->installKeySet();
        $this->tick();
        $this->api('POST', '/api/v1/reports', ['domain' => 'social.example.org', 'category' => 'moderation', 'text' => 'My reports about harassment were ignored for three weeks.'], null, 202);
        $container = static::getContainer();
        $container->get(\App\Service\Blocklist::class)->add('blocked.example.net', 'manual', 'Spam.');
        $container->get(\App\Curation\CurationService::class)->declareConflict($this->curator('alice@nextcloud.test'), $this->curator('bob@nextcloud.test'), $this->server('social.example.org'), 'Bob runs it.');
        $this->em->flush();
        $container->get('cache.rate_limiter')->clear();
    }

    private function auditRows(): int
    {
        $this->em->clear();

        return $this->em->getRepository(AuditLogEntry::class)->count([]);
    }

    /**
     * @return array<string, Route>
     */
    private function routes(): array
    {
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all();
        $routes = array_filter($routes, static fn (Route $route, string $name): bool => !str_starts_with($name, '_'), \ARRAY_FILTER_USE_BOTH);
        self::assertNotEmpty($routes);

        return $routes;
    }

    /**
     * @return array<string, Route>
     */
    private function consoleRoutes(): array
    {
        return array_filter(
            $this->routes(),
            static fn (Route $route, string $name): bool => str_starts_with($route->getPath(), '/console') && !\in_array($name, self::PUBLIC_CONSOLE_ROUTES, true),
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<string>
     */
    private function methods(Route $route): array
    {
        return [] === $route->getMethods() ? ['GET'] : array_values($route->getMethods());
    }

    /**
     * Fills in the route's placeholders with values that match its requirements.
     */
    private function url(Route $route): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($route): string {
            $requirement = (string) $route->getRequirement($m[1]);

            return match (true) {
                'domain' === $m[1] => 'social.example.org',
                (bool) preg_match('/^[a-z-]+(\|[a-z-]+)+$/', $requirement) => explode('|', $requirement)[0],
                '\d+' === $requirement || 'id' === $m[1] => '1',
                default => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            };
        }, $route->getPath());
    }
}
