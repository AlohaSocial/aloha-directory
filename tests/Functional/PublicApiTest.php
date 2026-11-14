<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Curation\CurationService;
use App\Entity\AuditLogEntry;
use App\Entity\Complaint;
use App\Service\SystemStateStore;
use App\Tests\Support\FunctionalTestCase;

/**
 * The anonymous endpoints: health check, complaint API, list failure telemetry and the /report form.
 */
final class PublicApiTest extends FunctionalTestCase
{
    private const BASE = 'https://directory.test-aloha.social';

    public function testHealthCheck(): void
    {
        $this->client->request('GET', self::BASE.'/healthz');
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok', 'database' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'no session for anonymous endpoints');

        $this->client->request('POST', self::BASE.'/healthz');
        self::assertResponseStatusCodeSame(405);
    }

    public function testHealthCheckReportsAnUnreachableDatabase(): void
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof \Doctrine\DBAL\Connection);
        $file = (string) ($connection->getParams()['path'] ?? '');
        if ('' === $file || !is_file($file) || !\function_exists('posix_geteuid') || 0 === posix_geteuid()) {
            self::markTestSkipped('Needs the SQLite test database and a non-root user.');
        }
        $connection->close();
        chmod($file, 0o000);
        try {
            $this->client->request('GET', self::BASE.'/healthz');
        } finally {
            chmod($file, 0o644);
        }
        self::assertResponseStatusCodeSame(503);
        self::assertSame(['status' => 'degraded', 'database' => false], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testComplaintsAreAcceptedForListedAndPausedServers(): void
    {
        $this->listServer($this->enrolServer('social.example.org'));
        $this->enrolServer('applied.example.org');

        $result = $this->api('POST', '/api/v1/reports', [
            'domain' => ' Social.Example.ORG ', 'category' => 'privacy',
            'text' => 'They sell user data to advertisers, see their FAQ.', 'contact_email' => 'reporter@mail.example.org',
        ], null, 202);
        self::assertTrue($result['received']);
        self::assertEmailCount(2, null, 'both active curators are told');
        $complaint = $this->em->find(Complaint::class, $result['id']);
        self::assertNotNull($complaint);
        self::assertSame('social.example.org', $complaint->server->domain);
        self::assertSame('privacy', $complaint->category);
        self::assertSame('reporter@mail.example.org', $complaint->reporterEmail);
        $audit = $this->em->getRepository(AuditLogEntry::class)->findOneBy(['action' => 'complaint.received']);
        self::assertNotNull($audit);
        self::assertSame(AuditLogEntry::ACTOR_PUBLIC, $audit->actorType);
        self::assertSame(['category' => 'privacy'], $audit->details);
        self::assertStringNotContainsString('reporter@', (string) json_encode($audit->details));

        // Applications and unknown servers cannot be reported.
        foreach (['applied.example.org', 'unknown.example.org', 'not a domain'] as $domain) {
            $error = $this->api('POST', '/api/v1/reports', ['domain' => $domain, 'category' => 'privacy', 'text' => str_repeat('Something is wrong. ', 2)], null, 422);
            self::assertSame('validation_failed', $error['error']);
            self::assertArrayHasKey('domain', $error['details']);
        }

        // A paused server can still be reported.
        $curation = static::getContainer()->get(CurationService::class);
        $curation->pause($this->curator('alice@nextcloud.test'), $this->server('social.example.org'), 'Investigating.');
        $this->api('POST', '/api/v1/reports', ['domain' => 'social.example.org', 'category' => 'other', 'text' => str_repeat('Still broken here. ', 2)], null, 202);
        self::assertCount(2, $this->em->getRepository(Complaint::class)->findAll());
    }

    public function testComplaintInputIsValidated(): void
    {
        $this->listServer($this->enrolServer('social.example.org'));
        $valid = ['domain' => 'social.example.org', 'category' => 'moderation', 'text' => 'My reports were ignored for weeks.'];
        // Every request below comes from its own address, so the per-address limit does not interfere.
        $address = 0;
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.++$address);

        foreach ([
            'category' => ['category' => 'spam-and-eggs'],
            'text' => ['text' => 'Too short.'],
            'contact_email' => ['contact_email' => 'not-an-address'],
        ] as $field => $override) {
            $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.++$address);
            $error = $this->api('POST', '/api/v1/reports', $override + $valid, null, 422);
            self::assertSame('validation_failed', $error['error']);
            self::assertArrayHasKey($field, $error['details'], (string) json_encode($error));
        }
        $error = $this->api('POST', '/api/v1/reports', ['category' => 'moderation'], null, 422);
        self::assertArrayHasKey('domain', $error['details']);
        self::assertArrayHasKey('text', $error['details']);

        // Malformed requests.
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.++$address);
        $this->raw('/api/v1/reports', '', 'application/json');
        $this->assertProblem(400, 'empty_body');
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.++$address);
        $this->raw('/api/v1/reports', (string) json_encode($valid), 'text/plain');
        $this->assertProblem(415, 'unsupported_media_type');
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.++$address);
        $this->raw('/api/v1/reports', '{"domain": ', 'application/json');
        $this->assertProblem(400, 'invalid_json');
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.++$address);
        $this->raw('/api/v1/reports', '["social.example.org"]', 'application/json');
        $this->assertProblem(400, 'invalid_json');
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.++$address);
        $this->raw('/api/v1/reports', (string) json_encode(['text' => str_repeat('x', 17_000)] + $valid), 'application/json');
        $this->assertProblem(413, 'body_too_large');
        $this->client->request('GET', self::BASE.'/api/v1/reports');
        self::assertResponseStatusCodeSame(405);

        self::assertSame([], $this->em->getRepository(Complaint::class)->findAll());
    }

    public function testComplaintsAreRateLimitedPerAddress(): void
    {
        $this->listServer($this->enrolServer('social.example.org'));
        $body = ['domain' => 'social.example.org', 'category' => 'moderation', 'text' => 'My reports were ignored for weeks.'];
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.7');
        for ($i = 0; $i < 5; ++$i) {
            $this->api('POST', '/api/v1/reports', $body, null, 202);
        }
        $error = $this->api('POST', '/api/v1/reports', $body, null, 429);
        self::assertSame('rate_limited', $error['error']);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        self::assertCount(5, $this->em->getRepository(Complaint::class)->findAll());

        // The form shares the limit.
        $crawler = $this->client->request('GET', self::BASE.'/report?domain=social.example.org');
        $this->client->submit($crawler->filter('form.report-form')->form(['category' => 'moderation', 'text' => 'My reports were ignored for weeks.']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.notice-error', 'You sent several reports in a short time. Please try again later.');

        // Another address is not affected.
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.9');
        $this->api('POST', '/api/v1/reports', $body, null, 202);
        self::assertCount(6, $this->em->getRepository(Complaint::class)->findAll());
    }

    public function testListFailuresAreCountedPerListAndReason(): void
    {
        $this->api('POST', '/api/v1/lists/failures', ['list' => 'servers', 'reason' => 'signature'], null, 204);
        $this->api('POST', '/api/v1/lists/failures', ['list' => 'servers', 'reason' => 'signature'], null, 204);
        $this->api('POST', '/api/v1/lists/failures', ['list' => 'keys', 'reason' => 'expired', 'client' => 'ignored'], null, 204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        self::assertSame(['list_failure.keys.expired' => 1, 'list_failure.servers.signature' => 2], $this->failureCounters());

        foreach ([['list' => 'other', 'reason' => 'signature'], ['list' => 'servers', 'reason' => 'boredom'], ['list' => ['servers'], 'reason' => 'serial'], ['list' => 'servers'], []] as $body) {
            $error = $this->api('POST', '/api/v1/lists/failures', $body, null, 422);
            self::assertSame('validation_failed', $error['error'], (string) json_encode($body));
        }
        $this->raw('/api/v1/lists/failures', '', 'application/json');
        $this->assertProblem(400, 'empty_body');
        $this->raw('/api/v1/lists/failures', (string) json_encode(['list' => 'servers', 'reason' => 'signature', 'pad' => str_repeat('x', 600)]), 'application/json');
        $this->assertProblem(413, 'body_too_large');
        self::assertSame(['list_failure.keys.expired' => 1, 'list_failure.servers.signature' => 2], $this->failureCounters(), 'refused reports are not counted');

        // Telemetry is limited to 60 reports per hour and address (10 used so far, malformed requests count too).
        for ($i = 0; $i < 50; ++$i) {
            $this->api('POST', '/api/v1/lists/failures', ['list' => 'search', 'reason' => 'format'], null, 204);
        }
        $this->api('POST', '/api/v1/lists/failures', ['list' => 'search', 'reason' => 'format'], null, 429);
        self::assertSame(['list_failure.keys.expired' => 1, 'list_failure.search.format' => 50, 'list_failure.servers.signature' => 2], $this->failureCounters());
    }

    /**
     * @return array<string, int>
     */
    private function failureCounters(): array
    {
        $counters = array_filter(static::getContainer()->get(SystemStateStore::class)->counters(), static fn (string $name): bool => str_starts_with($name, 'list_failure.'), \ARRAY_FILTER_USE_KEY);
        ksort($counters);

        return $counters;
    }

    public function testReportFormAcceptsValidReportsAndExplainsErrors(): void
    {
        $this->listServer($this->enrolServer('social.example.org'));
        $crawler = $this->client->request('GET', self::BASE.'/report?domain=social.example.org');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('social.example.org', $crawler->filter('input#domain')->attr('value'));

        // Errors keep what was typed and say what is wrong, per field.
        $form = $crawler->filter('form.report-form')->form();
        $crawler = $this->client->submit($form, ['domain' => 'unknown.example.org', 'category' => 'privacy', 'text' => 'Short.', 'contact_email' => 'nope']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#domain-error');
        self::assertSelectorExists('#text-error');
        self::assertSelectorExists('#contact_email-error');
        self::assertSelectorNotExists('#category-error');
        self::assertSame('true', $crawler->filter('input#domain')->attr('aria-invalid'));
        self::assertSame('unknown.example.org', $crawler->filter('input#domain')->attr('value'));
        self::assertSame('Short.', $crawler->filter('textarea#text')->text());
        self::assertSame('privacy', $crawler->filter('select#category option[selected]')->attr('value'));
        self::assertSame([], $this->em->getRepository(Complaint::class)->findAll());

        // The corrected form is accepted; the contact address is optional.
        $form = $crawler->filter('form.report-form')->form();
        $crawler = $this->client->submit($form, ['domain' => 'social.example.org', 'text' => 'They sell user data to advertisers, see their FAQ.', 'contact_email' => '']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice-success', 'Thank you');
        self::assertCount(0, $crawler->filter('form.report-form'));
        $complaints = $this->em->getRepository(Complaint::class)->findAll();
        self::assertCount(1, $complaints);
        self::assertNull($complaints[0]->reporterEmail);
        self::assertSame('privacy', $complaints[0]->category);
    }

    public function testReportFormDropsBotsAndForgedRequests(): void
    {
        $this->listServer($this->enrolServer('social.example.org'));
        $crawler = $this->client->request('GET', self::BASE.'/report');
        self::assertSame('', $crawler->filter('input#domain')->attr('value'));

        // The honeypot field: the bot sees a thank-you page, nothing is stored or mailed.
        $form = $crawler->filter('form.report-form')->form();
        $this->client->submit($form, ['domain' => 'social.example.org', 'category' => 'other', 'text' => 'Buy cheap watches at our shop now!!!', 'website' => 'https://spam.example.net']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice-success', 'Thank you');
        self::assertEmailCount(0);

        // A forged token.
        $this->client->request('POST', self::BASE.'/report', ['domain' => 'social.example.org', 'category' => 'privacy', 'text' => str_repeat('x', 30), '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.notice-error', 'Your session expired. Please send the form again.');
        self::assertSame([], $this->em->getRepository(Complaint::class)->findAll());
    }

    private function raw(string $path, string $body, string $contentType): void
    {
        $this->client->request('POST', self::BASE.$path, [], [], ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    private function assertProblem(int $status, string $error): void
    {
        self::assertResponseStatusCodeSame($status);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($error, $body['error']);
    }
}
