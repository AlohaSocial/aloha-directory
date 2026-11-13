<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Blocklist;
use App\Tests\Support\Fixtures;
use App\Tests\Support\FunctionalTestCase;

final class ApiSecurityTest extends FunctionalTestCase
{
    public function testUnsignedAndForeignSignaturesAreRejected(): void
    {
        $fake = $this->enrolServer('a.example.org');
        $other = $this->enrolServer('b.example.org');

        self::assertSame('missing_signature', $this->api('GET', '/api/v1/servers/a.example.org/status', null, null, 401)['error']);
        // b signs a request for a's resource with b's key.
        self::assertSame('unknown_key', $this->api('GET', '/api/v1/servers/a.example.org/status', null, $other, 401)['error']);
        self::assertSame('a.example.org', $this->api('GET', '/api/v1/servers/a.example.org/status', null, $fake)['domain']);
        self::assertSame('unknown_key', $this->api('GET', '/api/v1/servers/nobody.example.org/status', null, $fake, 401)['error']);
    }

    public function testReplayedRequestIsRejected(): void
    {
        $fake = $this->enrolServer('a.example.org');
        $vars = $fake->signedServerVars('GET', '/api/v1/servers/a.example.org/status', '', $this->clock->now()->getTimestamp(), 'directory.test-aloha.social');
        $this->client->request('GET', 'https://directory.test-aloha.social/api/v1/servers/a.example.org/status', [], [], $vars);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', 'https://directory.test-aloha.social/api/v1/servers/a.example.org/status', [], [], $vars);
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('replayed_signature', (string) $this->client->getResponse()->getContent());
    }

    public function testVerificationNeedsTheChallengeOnTheDomain(): void
    {
        $fake = $this->fake('c.example.org');
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'c.example.org', 'public_key' => $fake->publicKey()], null, 201);
        $fake->challenge = 'wrong';
        self::assertSame('challenge_mismatch', $this->verify($fake, 'failed')['error']);

        // A server that is not Aloha cannot be listed.
        $result = $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'c.example.org', 'public_key' => $fake->publicKey()], null, 201);
        $fake->challenge = $result['challenge'];
        $fake->softwareName = 'mastodon';
        $this->clock->sleep(1);
        self::assertSame('not_aloha', $this->verify($fake, 'failed')['error']);

        // Nor an outdated one.
        $fake->softwareName = 'aloha';
        $fake->version = '0.26.140';
        $this->clock->sleep(1);
        self::assertSame('version_too_old', $this->verify($fake, 'failed')['error']);

        // Expired challenge.
        $fake->version = '0.27.0';
        $this->clock->sleep(3700);
        self::assertSame('challenge_expired', $this->api('POST', '/api/v1/servers/c.example.org/verify', null, $fake, 410)['error']);
    }

    public function testBlockedDomainsCannotEnrol(): void
    {
        $fake = $this->fake('node1.spam-network.net');
        static::getContainer()->get(Blocklist::class)->add('spam-network.net', 'oliphant-tier0', 'Harassment');
        $this->em->flush();
        $result = $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'node1.spam-network.net', 'public_key' => $fake->publicKey()], null, 403);
        self::assertSame('domain_blocked', $result['error']);
    }

    public function testInputLimits(): void
    {
        $fake = $this->enrolServer('a.example.org');
        $this->client->request('PUT', 'https://directory.test-aloha.social/api/v1/servers/a.example.org/profile', [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', 70_000));
        self::assertResponseStatusCodeSame(413);

        $profile = Fixtures::profile('a.example.org');
        $profile['terms']['rules'] = [];
        $result = $this->api('PUT', '/api/v1/servers/a.example.org/profile', $profile, $fake, 422);
        self::assertSame('validation_failed', $result['error']);
        self::assertArrayHasKey('terms.rules', $result['details']);

        $this->client->request('POST', 'https://directory.test-aloha.social/api/v1/servers/enrol', [], [], ['CONTENT_TYPE' => 'text/plain'], '{}');
        self::assertResponseStatusCodeSame(415);
        $this->client->request('POST', 'https://directory.test-aloha.social/api/v1/servers/enrol', [], [], ['CONTENT_TYPE' => 'application/json'], '{"domain":');
        self::assertResponseStatusCodeSame(400);
    }

    public function testHeartbeatRules(): void
    {
        $fake = $this->enrolServer('a.example.org');
        $this->heartbeat($fake);
        // Too frequent.
        $this->clock->sleep(60);
        $response = $this->heartbeat($fake, expected: 429);
        self::assertSame('too_frequent', $response['error']);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));

        // Clock skew.
        $this->clock->sleep(3600);
        $hb = Fixtures::heartbeat('a.example.org', $this->clock->now()->modify('-20 minutes'));
        self::assertSame('clock_skew', $this->api('PUT', '/api/v1/servers/a.example.org/heartbeat', $hb, $fake, 422)['error']);

        // Heartbeat for another domain.
        $hb = Fixtures::heartbeat('other.example.org', $this->clock->now());
        self::assertSame('domain_mismatch', $this->api('PUT', '/api/v1/servers/a.example.org/heartbeat', $hb, $fake, 422)['error']);
    }

    public function testEnrolIsRateLimited(): void
    {
        $fake = $this->fake('x.example.org');
        for ($i = 0; $i < 10; ++$i) {
            $this->api('POST', '/api/v1/servers/enrol', ['domain' => "n{$i}.example.org", 'public_key' => $fake->publicKey()], null, 201);
        }
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'n11.example.org', 'public_key' => $fake->publicKey()], null, 429);
    }

    public function testWeakKeysAreRefused(): void
    {
        $result = $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'w.example.org', 'public_key' => str_repeat('A', 43)], null, 422);
        self::assertSame('invalid_public_key', $result['error']);
    }

    public function testSecurityHeaders(): void
    {
        $this->client->request('GET', 'https://directory.test-aloha.social/healthz');
        $headers = $this->client->getResponse()->headers;
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('max-age=', (string) $headers->get('Strict-Transport-Security'));

        $this->client->request('GET', 'https://directory.test-aloha.social/report');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("script-src 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('unsafe-inline', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testMetricsNeedTheToken(): void
    {
        $this->client->request('GET', 'https://directory.test-aloha.social/metrics');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', 'https://directory.test-aloha.social/metrics', [], [], ['HTTP_AUTHORIZATION' => 'Bearer test-metrics-token']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('aloha_directory_free_places', (string) $this->client->getResponse()->getContent());
    }
}
