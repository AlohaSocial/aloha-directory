<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Curation\CurationException;
use App\Curation\CurationService;
use App\Entity\Curator;
use App\Enum\ProposalAction;
use App\Enum\ServerState;
use App\Tests\Support\FunctionalTestCase;

/**
 * The whole path of concept 6–8: enrol, verify, profile, heartbeats, checks, two-curator listing,
 * signed publication, capacity buckets, pause, search providers and withdrawal.
 */
final class ListingLifecycleTest extends FunctionalTestCase
{
    public function testServerGoesFromEnrolmentToSignedList(): void
    {
        $this->installKeySet();
        $fake = $this->enrolServer('social.example.org');
        self::assertSame(ServerState::Applied, $this->server('social.example.org')->state);

        $server = $this->listServer($fake);
        self::assertSame(ServerState::Listed, $server->state);
        self::assertNotNull($server->nextReviewAt);

        $result = $this->tick();
        self::assertNotEmpty($result['builds']);
        $payload = $this->publishedList('servers');
        self::assertCount(1, $payload['entries']);
        $entry = $payload['entries'][0];
        self::assertSame('social.example.org', $entry['domain']);
        self::assertSame('listed', $entry['state']);
        self::assertEquals(['mode' => 'approval', 'email_required' => true, 'state' => 'open', 'capacity' => 'plenty', 'approval_wait' => 'short'], $entry['signup']);
        self::assertStringStartsWith('sha256:', $entry['logo'], 'the logo was fetched and published content-addressed');
        self::assertFileExists(static::getContainer()->getParameter('kernel.project_dir').'/var/test-output/media/'.substr($entry['logo'], 7).'.webp');

        // Exact numbers never leave the directory (concept 13.3).
        $raw = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/var/test-output/lists/v1/servers.json');
        $decoded = base64_decode(strtr((string) json_decode($raw, true)['payload'], '-_', '+/'));
        self::assertStringNotContainsString('412', $decoded);
        self::assertStringNotContainsString('1588', $decoded);
        self::assertStringNotContainsString('admin@', $decoded);

        // The static website shows the server.
        $site = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/var/test-output/index.html');
        self::assertStringContainsString('Example Social', $site);

        // Nothing changed: no rebuild.
        self::assertSame([], $this->tick()['builds']);

        // A heartbeat that crosses a bucket threshold rebuilds the list with a higher serial.
        $serial = $payload['serial'];
        $this->clock->sleep(3600);
        $this->heartbeat($fake, available: 0);
        $this->clock->sleep(60);
        $this->tick();
        $payload = $this->publishedList('servers');
        self::assertGreaterThan($serial, $payload['serial']);
        self::assertSame('full', $payload['entries'][0]['state']);
        self::assertSame('closed', $payload['entries'][0]['signup']['state']);

        // A small change inside the same bucket does not rebuild.
        $this->clock->sleep(3600);
        $this->heartbeat($fake, available: 150);
        $this->clock->sleep(60);
        $this->tick();
        $this->clock->sleep(3600);
        $this->heartbeat($fake, available: 160);
        $this->clock->sleep(60);
        self::assertSame([], $this->tick()['builds']);

        // A curator pause removes the server within the next tick.
        $alice = $this->curator('alice@nextcloud.test');
        static::getContainer()->get(CurationService::class)->pause($alice, $this->server('social.example.org'), 'Investigating a complaint.');
        $this->clock->sleep(60);
        $this->tick();
        self::assertSame([], $this->publishedList('servers')['entries']);
        self::assertSame(ServerState::Paused, $this->server('social.example.org')->state);

        // Withdrawal by the admin.
        $this->api('DELETE', '/api/v1/servers/social.example.org', null, $fake);
        self::assertSame(ServerState::Withdrawn, $this->server('social.example.org')->state);
        $this->heartbeat($fake, expected: 409);
    }

    public function testTwoCuratorRuleAndConflicts(): void
    {
        $fake = $this->enrolServer('two.example.org');
        $server = $this->bringToReview($fake);
        $alice = $this->createCurator('alice@nextcloud.test', true);
        $bob = $this->createCurator('bob@nextcloud.test');
        $carol = $this->createCurator('carol@nextcloud.test');
        $curation = static::getContainer()->get(CurationService::class);

        // A proposal to list needs a completed checklist first.
        try {
            $curation->propose($alice, $server, ProposalAction::List, [], 'Good server.');
            self::fail('Listing without a review must be refused.');
        } catch (CurationException) {
        }
        $curation->recordReview($alice, $server, 'initial', array_fill_keys(['operator', 'moderation', 'legal', 'experience', 'mix'], true), 'Fine.');
        $proposal = $curation->propose($alice, $server, ProposalAction::List, [], 'Good server.');

        // The proposer cannot confirm.
        try {
            $curation->confirm($alice, $proposal);
            self::fail('Self-confirmation must be refused.');
        } catch (CurationException $e) {
            self::assertStringContainsString('second curator', $e->getMessage());
        }

        // A curator with a conflict of interest cannot confirm.
        $curation->declareConflict($carol, $carol, $server, 'I run this server.');
        try {
            $curation->confirm($carol, $proposal);
            self::fail('A conflicted curator must not confirm.');
        } catch (CurationException $e) {
            self::assertStringContainsString('conflict', $e->getMessage());
        }

        $curation->confirm($bob, $proposal);
        $server = $this->server('two.example.org');
        self::assertSame(ServerState::Listed, $server->state);

        // Featuring needs 90 days of listing.
        $proposal = $curation->propose($this->curator('alice@nextcloud.test'), $server, ProposalAction::Feature, [], 'Strong German server.');
        try {
            $curation->confirm($this->curator('bob@nextcloud.test'), $proposal);
            self::fail('Featuring a new server must be refused.');
        } catch (CurationException $e) {
            self::assertStringContainsString('90 days', $e->getMessage());
        }

        // Weight changes are bounded.
        try {
            $curation->propose($this->curator('alice@nextcloud.test'), $this->server('two.example.org'), ProposalAction::Weight, ['weight' => '3'], 'Boost.');
            self::fail('Out-of-range weight must be refused.');
        } catch (CurationException) {
        }

        // Delisting creates a public transparency entry and blocks re-application for six months.
        $server = $this->server('two.example.org');
        $proposal = $curation->propose($this->curator('alice@nextcloud.test'), $server, ProposalAction::Delist, ['rule' => 'active_moderation', 'public_reason' => 'Reports went unanswered for weeks.'], 'See complaint #1.');
        $curation->confirm($this->curator('bob@nextcloud.test'), $proposal);
        self::assertSame(ServerState::Delisted, $this->server('two.example.org')->state);
        self::assertCount(1, $this->em->getRepository(\App\Entity\TransparencyEntry::class)->findAll());

        $result = $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'two.example.org', 'public_key' => $fake->publicKey()], null, 403);
        self::assertSame('reapply_too_early', $result['error']);
    }

    public function testSearchProviderList(): void
    {
        $this->installKeySet();
        $fake = $this->enrolServer('search.example.net');
        $server = $this->listServer($fake);
        $curation = static::getContainer()->get(CurationService::class);
        $proposal = $curation->propose($this->curator('alice@nextcloud.test'), $server, ProposalAction::EnableSearch, [], 'Good index policy.');
        $curation->confirm($this->curator('bob@nextcloud.test'), $proposal);

        $this->tick();
        $entries = $this->publishedList('search')['entries'];
        self::assertSame('aloha', $entries[0]['kind']);
        self::assertSame('search.example.net', $entries[0]['domain']);
        self::assertSame(['accounts', 'hashtags'], $entries[0]['capabilities']);
        self::assertSame(60, $entries[0]['rate_limit_per_minute']);
        self::assertSame('atproto_appview', $entries[1]['kind']);
        self::assertSame('https://public.api.bsky.app', $entries[1]['url']);

        // A provider without a fresh heartbeat leaves the search list.
        $this->clock->sleep(4 * 3600);
        $this->tick();
        self::assertSame(['atproto_appview'], array_column($this->publishedList('search')['entries'], 'kind'));
    }

    public function testKeyRotation(): void
    {
        $fake = $this->enrolServer('rotate.example.org');
        $oldVars = $fake;
        // Rotation signed with the old key, with proof of the new key.
        [$newSecret, $newPublic] = \App\Tests\Support\TestKeys::random();
        $fingerprint = \App\Crypto\Ed25519::fingerprint($newPublic);
        $proof = \App\Crypto\Base64Url::encode(\App\Crypto\Ed25519::sign('aloha-directory-key-rotation:rotate.example.org:'.$fingerprint, $newSecret));
        $this->api('POST', '/api/v1/servers/rotate.example.org/key', ['public_key' => \App\Crypto\Base64Url::encode($newPublic), 'proof' => $proof], $oldVars);
        self::assertSame($fingerprint, $this->server('rotate.example.org')->keyId);

        // The old key no longer works.
        $result = $this->api('GET', '/api/v1/servers/rotate.example.org/status', null, $fake, 401);
        self::assertSame('unknown_key', $result['error']);
    }
}
