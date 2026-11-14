<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Check\Checker;
use App\Check\Checks;
use App\Entity\ServerCheck;
use App\Entity\ServerCheckDaily;
use App\Enum\ServerState;
use App\Net\DnsResolver;
use App\Service\Blocklist;
use App\Tests\Support\FakeAlohaServer;
use App\Tests\Support\FakeDnsResolver;
use App\Tests\Support\Fixtures;
use App\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Each automatic check of concept 7.2 on its own: what passes, what fails and with which message.
 */
final class CheckerTest extends FunctionalTestCase
{
    private const DOMAIN = 'social.example.org';

    /** @var array<string, \Closure(): MockResponse> url => response, consulted before the fake servers */
    private array $overrides = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->overrides = [];
        $http = static::getContainer()->get('app.outbound_http_client');
        \assert($http instanceof MockHttpClient);
        $http->setResponseFactory(function (string $method, string $url): MockResponse {
            if (isset($this->overrides[$url])) {
                return ($this->overrides[$url])();
            }
            foreach ($this->fakes as $fake) {
                if (null !== ($response = $fake->respond($method, $url))) {
                    return $response;
                }
            }

            return new MockResponse('', ['error' => 'Could not resolve host']);
        });
    }

    public function testEverythingPassesAndTheApplicationGoesToReview(): void
    {
        $fake = $this->ready();
        $records = $this->check();

        self::assertSame(Checks::BEFORE_REVIEW, array_keys($records), 'atproto is only checked when enabled');
        foreach ($records as $name => $record) {
            self::assertTrue($record->passed, $name.': '.$record->message);
        }
        self::assertSame('Valid certificate; HSTS enabled.', $records[Checks::HTTPS]->message);
        self::assertSame('Aloha 0.27.0.', $records[Checks::NODEINFO]->message);
        self::assertSame('Terms, privacy policy and moderation policy load; 3 rules.', $records[Checks::TERMS]->message);
        self::assertSame('Signup is open (approval), as in the heartbeat.', $records[Checks::INSTANCE_SIGNUP]->message);

        $server = $this->server(self::DOMAIN);
        self::assertSame(ServerState::InReview, $server->state);
        self::assertSame('abuse@social.example.org', $server->abuseTestMailSentTo);
        self::assertEquals($this->clock->now(), $server->abuseTestMailSentAt);
        self::assertNull($server->atprotoVerified);

        // The daily history counts one pass per check.
        $daily = $this->em->getRepository(ServerCheckDaily::class)->findBy(['server' => $server]);
        self::assertCount(\count(Checks::BEFORE_REVIEW), $daily);
        foreach ($daily as $row) {
            self::assertSame([1, 0], [$row->passes, $row->failures], $row->name);
        }
        unset($fake);
    }

    public function testRunsAreRecordedNotDuplicated(): void
    {
        $this->ready();
        $this->overrides['https://social.example.org/status.php'] = static fn (): MockResponse => new MockResponse('', ['http_code' => 502]);
        $this->check();
        $this->clock->sleep(600);
        $records = $this->check();

        self::assertFalse($records[Checks::HTTPS]->passed);
        self::assertSame(2, $records[Checks::HTTPS]->consecutiveFailures);
        self::assertSame(\count(Checks::BEFORE_REVIEW), \count($this->em->getRepository(ServerCheck::class)->findAll()));
        $daily = $this->em->getRepository(ServerCheckDaily::class)->findOneBy(['name' => Checks::HTTPS]);
        self::assertSame([0, 2], [$daily->passes, $daily->failures]);
        self::assertSame(ServerState::Applied, $this->server(self::DOMAIN)->state, 'one failing check keeps the application out of review');
    }

    /**
     * @param \Closure(self, FakeAlohaServer): void $arrange
     */
    #[DataProvider('outcomes')]
    public function testCheckOutcome(\Closure $arrange, string $check, bool $passed, string $message): void
    {
        $fake = $this->ready();
        $arrange($this, $fake);
        $records = $this->check();

        self::assertArrayHasKey($check, $records);
        self::assertSame($passed, $records[$check]->passed, $records[$check]->message);
        self::assertStringContainsString($message, $records[$check]->message);
        if (!$passed) {
            self::assertSame(ServerState::Applied, $this->server(self::DOMAIN)->state);
        }
    }

    public static function outcomes(): iterable
    {
        $body = static fn (string $path, string $body, array $info = []): \Closure => static function (self $t) use ($path, $body, $info): void {
            $t->overrides['https://social.example.org'.$path] = static fn (): MockResponse => new MockResponse($body, $info);
        };

        // https
        yield 'status.php 503' => [static function (self $t, FakeAlohaServer $f): void { $f->statusOverrides['/status.php'] = 503; }, Checks::HTTPS, false, '/status.php answered HTTP 503.'];
        yield 'certificate error' => [$body('/status.php', '', ['error' => 'SSL certificate problem: certificate has expired']), Checks::HTTPS, false, 'certificate has expired'];
        yield 'status.php not json' => [$body('/status.php', '<html>'), Checks::HTTPS, false, 'did not return valid JSON'];
        yield 'status.php too deep' => [$body('/status.php', '{"a":{"b":{"c":{"d":{}}}}}'), Checks::HTTPS, false, 'did not return valid JSON'];
        yield 'maintenance mode' => [$body('/status.php', '{"installed":true,"maintenance":true}'), Checks::HTTPS, false, 'not installed or in maintenance mode'];
        yield 'not installed' => [$body('/status.php', '{"installed":false}'), Checks::HTTPS, false, 'not installed or in maintenance mode'];
        yield 'installed as string' => [$body('/status.php', '{"installed":"true"}'), Checks::HTTPS, false, 'not installed or in maintenance mode'];
        yield 'no hsts' => [$body('/status.php', '{"installed":true,"maintenance":false}'), Checks::HTTPS, true, 'HSTS is recommended but not enabled.'];
        yield 'redirect to another host' => [$body('/status.php', '', ['http_code' => 301, 'response_headers' => ['Location' => 'https://evil.example.net/status.php']]), Checks::HTTPS, false, 'redirects to another host'];

        // nodeinfo
        yield 'other software' => [static function (self $t, FakeAlohaServer $f): void { $f->softwareName = 'Mastodon'; }, Checks::NODEINFO, false, 'Nodeinfo names "mastodon", not Aloha.'];
        yield 'old version is a warning' => [static function (self $t, FakeAlohaServer $f): void { $f->version = '0.26.9'; }, Checks::NODEINFO, true, 'Aloha 0.26.9; below the minimum 0.27.0'];
        yield 'version differs from heartbeat' => [static function (self $t, FakeAlohaServer $f): void { $f->version = '0.28.0'; }, Checks::NODEINFO, true, 'Aloha 0.28.0 (the heartbeat reports 0.27.0).'];
        yield 'nodeinfo missing' => [static function (self $t, FakeAlohaServer $f): void { $f->statusOverrides['/.well-known/nodeinfo'] = 404; }, Checks::NODEINFO, false, '/.well-known/nodeinfo answered HTTP 404.'];

        // terms
        yield 'privacy policy 404' => [static function (self $t, FakeAlohaServer $f): void { $f->statusOverrides['/privacy'] = 404; }, Checks::TERMS, false, 'The privacy policy answered HTTP 404.'];
        yield 'moderation policy unreachable' => [$body('/rules', '', ['error' => 'Connection reset']), Checks::TERMS, false, 'The moderation policy does not load'];
        yield 'terms too large' => [$body('/terms', str_repeat('x', 3_000_001)), Checks::TERMS, false, 'The terms of service does not load'];

        // abuse contact
        yield 'abuse domain without mail' => [static function (self $t): void { $t->dns()->mailDomains['social.example.org'] = false; }, Checks::ABUSE_CONTACT, false, 'The domain of abuse@social.example.org cannot receive mail'];
        yield 'abuse contact first seen' => [static function (): void {}, Checks::ABUSE_CONTACT, true, 'abuse@social.example.org can receive mail; a test message is sent to it.'];

        // instance signup
        yield 'instance 404' => [static function (self $t, FakeAlohaServer $f): void { $f->statusOverrides['/api/v2/instance'] = 404; }, Checks::INSTANCE_SIGNUP, false, '/api/v2/instance answered HTTP 404.'];
        yield 'instance without registrations' => [$body('/api/v2/instance', '{"domain":"social.example.org"}'), Checks::INSTANCE_SIGNUP, false, 'has no registrations block'];
        yield 'instance registrations enabled as string' => [$body('/api/v2/instance', '{"registrations":{"enabled":"true"}}'), Checks::INSTANCE_SIGNUP, false, 'has no registrations block'];
        yield 'instance says closed' => [static function (self $t, FakeAlohaServer $f): void { $f->registrationsOpen = false; }, Checks::INSTANCE_SIGNUP, false, '/api/v2/instance says registrations are closed, the heartbeat says open.'];
        yield 'approval disagrees' => [static function (self $t, FakeAlohaServer $f): void { $f->approvalRequired = false; }, Checks::INSTANCE_SIGNUP, false, 'disagree about approval'];
        yield 'both closed' => [static function (self $t, FakeAlohaServer $f): void {
            $f->registrationsOpen = false;
            $t->clock->sleep(3600);
            $t->heartbeat($f, open: false);
        }, Checks::INSTANCE_SIGNUP, true, 'Signup is closed, as in the heartbeat.'];

        // block list
        yield 'blocked domain' => [static function (self $t): void {
            static::getContainer()->get(Blocklist::class)->add('social.example.org', 'manual', 'Harassment campaign');
            $t->em->flush();
        }, Checks::BLOCKLIST, false, 'Blocked by "manual" (social.example.org): Harassment campaign'];
        yield 'blocked parent domain' => [static function (self $t): void {
            static::getContainer()->get(Blocklist::class)->add('example.org', 'oliphant', 'Whole domain');
            $t->em->flush();
        }, Checks::BLOCKLIST, false, 'Blocked by "oliphant" (example.org)'];
        yield 'sibling domain blocked' => [static function (self $t): void {
            static::getContainer()->get(Blocklist::class)->add('other.example.org', 'manual', 'Not us');
            $t->em->flush();
        }, Checks::BLOCKLIST, true, 'Not on any block list.'];

        // heartbeats
        yield 'last heartbeat too old' => [static function (self $t): void { $t->clock->sleep(2 * 3600 + 1); }, Checks::HEARTBEATS, false, 'The last heartbeat is more than two hours old.'];
    }

    public function testTooFewHeartbeats(): void
    {
        $this->enrolServer(self::DOMAIN);
        $this->heartbeat($this->fake(self::DOMAIN));
        $this->clock->sleep(3600);
        $this->heartbeat($this->fake(self::DOMAIN));
        $records = $this->check();
        self::assertFalse($records[Checks::HEARTBEATS]->passed);
        self::assertSame('2 of 3 heartbeats received.', $records[Checks::HEARTBEATS]->message);
        self::assertTrue($records[Checks::INSTANCE_SIGNUP]->passed, 'two heartbeats are enough to compare the signup state');
    }

    public function testNoHeartbeatYet(): void
    {
        $this->enrolServer(self::DOMAIN);
        $records = $this->check();
        self::assertSame('No heartbeat has been received yet.', $records[Checks::INSTANCE_SIGNUP]->message);
        self::assertSame('0 of 3 heartbeats received.', $records[Checks::HEARTBEATS]->message);
    }

    public function testGapBetweenHeartbeats(): void
    {
        $fake = $this->enrolServer(self::DOMAIN);
        $this->heartbeat($fake);
        $this->clock->sleep(3 * 3600);
        $this->heartbeat($fake);
        $this->clock->sleep(3600);
        $this->heartbeat($fake);
        self::assertSame('The last three heartbeats were not on time.', $this->check()[Checks::HEARTBEATS]->message);
    }

    public function testAbuseTestMailIsRateLimited(): void
    {
        $fake = $this->ready();
        $this->check();
        $first = $this->server(self::DOMAIN)->abuseTestMailSentAt;
        self::assertNotNull($first);

        // Same address: never sent again, and the message says so.
        $this->clock->sleep(3 * 86400);
        $this->freshHeartbeats($fake);
        self::assertStringContainsString('test message sent '.$first->format('Y-m-d'), $this->check()[Checks::ABUSE_CONTACT]->message);
        self::assertEquals($first, $this->server(self::DOMAIN)->abuseTestMailSentAt);

        // A new address within 24 hours of the last test mail: not sent yet.
        $this->changeAbuseEmail($fake, 'abuse-new@social.example.org');
        $server = $this->server(self::DOMAIN);
        $server->abuseTestMailSentAt = $this->clock->now()->modify('-23 hours');
        $this->em->flush();
        $this->check();
        self::assertSame('abuse@social.example.org', $this->server(self::DOMAIN)->abuseTestMailSentTo);

        // After 24 hours it is.
        $this->clock->sleep(3600 + 1);
        $this->freshHeartbeats($fake);
        $this->check();
        self::assertSame('abuse-new@social.example.org', $this->server(self::DOMAIN)->abuseTestMailSentTo);
        self::assertEquals($this->clock->now(), $this->server(self::DOMAIN)->abuseTestMailSentAt);
    }

    public function testFailingAbuseContactSendsNoMail(): void
    {
        $this->ready();
        $this->dns()->mailDomains['social.example.org'] = false;
        $this->check();
        self::assertNull($this->server(self::DOMAIN)->abuseTestMailSentTo);
    }

    public function testAtprotoIsCheckedWhenEnabled(): void
    {
        $fake = $this->ready(atproto: ['enabled' => true, 'handle_example' => 'alice.social.example.org']);
        $this->overrides['https://social.example.org/.well-known/did.json'] = static fn (): MockResponse => new MockResponse('{"id":"did:web:social.example.org"}');
        $this->overrides['https://alice.social.example.org/.well-known/atproto-did'] = static fn (): MockResponse => new MockResponse("did:plc:abc123\n");

        $records = $this->check();
        self::assertTrue($records[Checks::ATPROTO]->passed, $records[Checks::ATPROTO]->message);
        self::assertTrue($this->server(self::DOMAIN)->atprotoVerified);

        $this->overrides['https://alice.social.example.org/.well-known/atproto-did'] = static fn (): MockResponse => new MockResponse('<html>not a did</html>');
        $this->clock->sleep(60);
        $records = $this->check();
        self::assertFalse($records[Checks::ATPROTO]->passed);
        self::assertSame('The example handle alice.social.example.org does not resolve to a DID.', $records[Checks::ATPROTO]->message);
        self::assertFalse($this->server(self::DOMAIN)->atprotoVerified);

        $this->overrides['https://alice.social.example.org/.well-known/atproto-did'] = static fn (): MockResponse => new MockResponse('', ['error' => 'Could not resolve host']);
        self::assertStringContainsString('does not resolve: ', $this->check()[Checks::ATPROTO]->message);

        $this->overrides['https://social.example.org/.well-known/did.json'] = static fn (): MockResponse => new MockResponse('{"id":"https://social.example.org"}');
        self::assertSame('/.well-known/did.json has no DID.', $this->check()[Checks::ATPROTO]->message);
        unset($fake);
    }

    public function testAtprotoHandleOnAnotherDomainIsNotFetched(): void
    {
        $this->ready(atproto: ['enabled' => true, 'handle_example' => 'alice.evil.example.net']);
        $this->overrides['https://social.example.org/.well-known/did.json'] = static fn (): MockResponse => new MockResponse('{"id":"did:web:social.example.org"}');
        $this->overrides['https://alice.evil.example.net/.well-known/atproto-did'] = static fn (): MockResponse => throw new \LogicException('The directory fetched a foreign handle.');

        self::assertTrue($this->check()[Checks::ATPROTO]->passed);
    }

    public function testAtprotoRecordIsRemovedWhenDisabled(): void
    {
        $fake = $this->ready(atproto: ['enabled' => true]);
        $this->overrides['https://social.example.org/.well-known/did.json'] = static fn (): MockResponse => new MockResponse('{"id":"did:web:social.example.org"}');
        self::assertArrayHasKey(Checks::ATPROTO, $this->check());

        $profile = Fixtures::profile(self::DOMAIN);
        $profile['atproto'] = ['enabled' => false];
        $this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake);
        $this->clock->sleep(60);
        self::assertArrayNotHasKey(Checks::ATPROTO, $this->check());
        self::assertNull($this->em->getRepository(ServerCheck::class)->findOneBy(['name' => Checks::ATPROTO]));
        self::assertNull($this->server(self::DOMAIN)->atprotoVerified);
    }

    public function testInactiveServersAreNotChecked(): void
    {
        $this->ready();
        $server = $this->server(self::DOMAIN);
        $server->state = ServerState::Rejected;
        $this->em->flush();
        $this->overrides['https://social.example.org/status.php'] = static fn (): MockResponse => throw new \LogicException('A rejected server was contacted.');

        self::assertSame([], static::getContainer()->get(Checker::class)->run($this->server(self::DOMAIN)));
        self::assertSame([], $this->em->getRepository(ServerCheck::class)->findAll());
    }

    // --- helpers -------------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $atproto
     */
    private function ready(?array $atproto = null): FakeAlohaServer
    {
        $fake = $this->enrolServer(self::DOMAIN);
        if (null !== $atproto) {
            $profile = Fixtures::profile(self::DOMAIN);
            $profile['atproto'] = $atproto;
            $this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake);
        }
        $this->freshHeartbeats($fake);

        return $fake;
    }

    private function freshHeartbeats(FakeAlohaServer $fake): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->clock->sleep(3600);
            $this->heartbeat($fake);
        }
    }

    private function changeAbuseEmail(FakeAlohaServer $fake, string $email): void
    {
        $profile = Fixtures::profile(self::DOMAIN);
        $profile['moderation']['abuse_email'] = $email;
        $this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake);
    }

    /**
     * @return array<string, ServerCheck>
     */
    private function check(): array
    {
        return static::getContainer()->get(Checker::class)->run($this->server(self::DOMAIN));
    }

    public function dns(): FakeDnsResolver
    {
        $dns = static::getContainer()->get(DnsResolver::class);
        \assert($dns instanceof FakeDnsResolver);

        return $dns;
    }
}
