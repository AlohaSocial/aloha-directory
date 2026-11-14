<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AuditLogEntry;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Helpers for driving the curator console through the browser client like a curator would: load a
 * page, submit one of its real forms (with its CSRF token), follow the redirect and read the flash.
 *
 * For use in subclasses of FunctionalTestCase.
 */
trait ConsoleBrowsing
{
    protected static string $base = 'https://directory.test-aloha.social';

    /**
     * Loads a console page and asserts that it rendered.
     */
    protected function page(string $path): Crawler
    {
        $crawler = $this->client->request('GET', self::$base.$path);
        self::assertResponseIsSuccessful($path.': '.$this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    /**
     * Submits the form that matches $selector on the page, filling in $values.
     *
     * @param array<string, mixed> $values
     */
    protected function submitForm(Crawler $crawler, string $selector, array $values = []): void
    {
        $form = $crawler->filter($selector);
        self::assertGreaterThan(0, $form->count(), 'No form matches '.$selector);
        $this->client->submit($form->first()->form(), $values);
    }

    /**
     * Submits a form with one of its buttons (which may carry a formaction).
     *
     * @param array<string, mixed> $values
     */
    protected function submitWithButton(Crawler $crawler, string $button, array $values = []): void
    {
        $node = $crawler->selectButton($button);
        self::assertGreaterThan(0, $node->count(), 'No button '.$button);
        $this->client->submit($node->first()->form(), $values);
    }

    /**
     * Expects a post/redirect/get answer, follows it and checks the flash message.
     */
    protected function followFlash(string $type, string $text, ?string $redirectPath = null): Crawler
    {
        $response = $this->client->getResponse();
        self::assertTrue($response->isRedirect(), 'Expected a redirect, got '.$response->getStatusCode().': '.mb_substr((string) $response->getContent(), 0, 500));
        if (null !== $redirectPath) {
            self::assertSame($redirectPath, parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));
        }
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        // Flash messages are direct children of <main> (other .flash-* boxes are page content).
        self::assertSelectorTextContains('main > .flash-'.$type, $text);
        if ('success' === $type) {
            self::assertSelectorNotExists('main > .flash-error');
        }

        return $crawler;
    }

    /**
     * A valid console CSRF token of the current session, for posting to routes whose form is not
     * on the page (as an attacker or a stale browser tab would).
     */
    protected function consoleToken(): string
    {
        // The list page has the rebuild form for every curator.
        $crawler = $this->client->request('GET', self::$base.'/console/lists');
        $token = $crawler->filter('form[action$="/console/lists/rebuild"] input[name="_csrf_token"]');
        self::assertGreaterThan(0, $token->count(), 'No CSRF token found');

        return (string) $token->first()->attr('value');
    }

    /**
     * Posts to a console route with a valid CSRF token.
     *
     * @param array<string, mixed> $fields
     */
    protected function post(string $path, array $fields = [], ?string $token = null): void
    {
        $this->client->request('POST', self::$base.$path, $fields + ['_csrf_token' => $token ?? $this->consoleToken()]);
    }

    /**
     * @return list<AuditLogEntry> newest last
     */
    protected function audit(?string $action = null, ?string $domain = null): array
    {
        $criteria = [];
        if (null !== $action) {
            $criteria['action'] = $action;
        }
        if (null !== $domain) {
            $criteria['serverDomain'] = $domain;
        }

        return array_values($this->em->getRepository(AuditLogEntry::class)->findBy($criteria, ['id' => 'ASC']));
    }

    protected function lastAudit(string $action, ?string $domain = null): AuditLogEntry
    {
        $entries = $this->audit($action, $domain);
        self::assertNotEmpty($entries, 'No audit entry '.$action);

        return $entries[\count($entries) - 1];
    }

    /**
     * @return list<Cookie> the cookies of the current browser, to switch between curators' sessions
     */
    protected function saveSession(): array
    {
        return $this->client->getCookieJar()->all();
    }

    /**
     * @param list<Cookie> $cookies
     */
    protected function restoreSession(array $cookies): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($cookies as $cookie) {
            $jar->set($cookie);
        }
    }

    protected function signInAs(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->consoleLogin($email);
        self::assertResponseRedirects(self::$base.'/console');
    }
}
