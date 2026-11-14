<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Check\Checker;
use App\Curation\CurationException;
use App\Curation\CurationService;
use App\Entity\CuratorConflict;
use App\Entity\PendingEnrolment;
use App\Entity\Review;
use App\Entity\TransparencyEntry;
use App\Enum\Decision;
use App\Enum\PauseKind;
use App\Enum\ServerState;
use App\Lists\KeySetService;
use App\Security\CuratorAccounts;
use App\Security\Passkeys;
use App\Security\SecondFactorProof;
use App\Tests\Support\Fixtures;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\SoftAuthenticator;
use App\Tests\Support\TestKeys;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Doctrine\ORM\OptimisticLockException;

/**
 * Regression tests for the findings of the security review (see SECURITY.md).
 */
final class SecurityReviewTest extends FunctionalTestCase
{
    private const BASE = 'https://directory.test-aloha.social';

    public function testStrangersCanNeitherEvictNorHijackAnEnrolment(): void
    {
        $admin = $this->fake('social.example.org');
        $result = $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'social.example.org', 'public_key' => $admin->publicKey(), 'contact_email' => 'admin@social.example.org'], null, 201);
        $admin->challenge = $result['challenge'];

        // An attacker enrols the same domain with their own key, and with the admin's (public) key.
        [, $attackerPublic] = TestKeys::random();
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'social.example.org', 'public_key' => TestKeys::encode($attackerPublic), 'contact_email' => 'evil@attacker.test'], null, 201);
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'social.example.org', 'public_key' => $admin->publicKey(), 'contact_email' => 'evil@attacker.test'], null, 201);
        self::assertCount(3, $this->em->getRepository(PendingEnrolment::class)->findBy(['domain' => 'social.example.org']));

        // The domain publishes the admin's challenge, so the admin's enrolment (and contact) wins.
        $this->verify($admin);
        $server = $this->server('social.example.org');
        self::assertSame('admin@social.example.org', $server->adminEmail);
        self::assertSame($admin->keyId(), $server->keyId);
        self::assertSame([], $this->em->getRepository(PendingEnrolment::class)->findBy(['domain' => 'social.example.org']), 'all pending enrolments of the domain are discarded');
    }

    public function testVerifyIsLimitedPerKey(): void
    {
        $fake = $this->fake('slow.example.org');
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'slow.example.org', 'public_key' => $fake->publicKey()], null, 201);
        $fake->challenge = 'wrong';
        for ($i = 0; $i < 12; ++$i) {
            $this->clock->sleep(1);
            $this->api('POST', '/api/v1/servers/slow.example.org/verify', null, $fake, 202);
            $this->runJobs();
        }
        $this->clock->sleep(1);
        self::assertSame('rate_limited', $this->api('POST', '/api/v1/servers/slow.example.org/verify', null, $fake, 429)['error']);
    }

    public function testListingNeedsAReviewOfTheCurrentProfile(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $server = $this->bringToReview($fake);
        $alice = $this->createCurator('alice@nextcloud.test', true);
        $curation = static::getContainer()->get(CurationService::class);
        $checklist = array_fill_keys(array_keys(\App\Curation\Covenant::INITIAL_CHECKLIST), true);

        // A checklist submitted for a profile that changed while the curator looked is refused.
        try {
            $curation->recordReview($alice, $server, Review::KIND_INITIAL, $checklist, 'Fine.', str_repeat('0', 64));
            self::fail('A review for a profile the curator did not see must be refused.');
        } catch (CurationException) {
        }
        $curation->recordReview($alice, $server, Review::KIND_INITIAL, $checklist, 'Fine.', $server->profileFingerprint());

        // The server swaps its operator after the review.
        $profile = Fixtures::profile('social.example.org');
        $profile['operator']['name'] = 'Someone Else Ltd';
        $profile['etag'] = 'W/"8"';
        $this->clock->sleep(1);
        $this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake);

        // Fresh objects, as in the curator's next request (the test client resets the entity manager).
        try {
            $curation->decide($this->curator('alice@nextcloud.test'), $this->server('social.example.org'), Decision::List, [], 'Good server.');
            self::fail('Listing a profile that changed after the review must fail.');
        } catch (CurationException $e) {
            self::assertStringContainsString('changed', $e->getMessage());
        }
        self::assertSame(ServerState::InReview, $this->server('social.example.org')->state);

        // A review of the new profile allows the listing.
        $server = $this->server('social.example.org');
        $curation->recordReview($this->curator('alice@nextcloud.test'), $server, Review::KIND_INITIAL, $checklist, 'New operator checked.', $server->profileFingerprint());
        $curation->decide($this->curator('alice@nextcloud.test'), $this->server('social.example.org'), Decision::List, [], 'Good server.');
        $server = $this->server('social.example.org');
        self::assertSame(ServerState::Listed, $server->state);
        self::assertSame('Someone Else Ltd', $server->approvedProfile['operator']['name']);
    }

    public function testResumingAPausedServerDoesNotApproveWaitingChanges(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $curation = static::getContainer()->get(CurationService::class);
        $curation->pause($this->curator('alice@nextcloud.test'), $this->server('social.example.org'), 'Checking a report.');

        $profile = Fixtures::profile('social.example.org');
        $profile['terms']['terms_url'] = 'https://social.example.org/phishing';
        $profile['etag'] = 'W/"8"';
        $this->clock->sleep(1);
        self::assertTrue($this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake)['pending_review']);

        $curation->decide($this->curator('bob@nextcloud.test'), $this->server('social.example.org'), Decision::List, [], 'Report resolved.');

        $server = $this->server('social.example.org');
        self::assertSame(ServerState::Listed, $server->state);
        self::assertSame('https://social.example.org/terms', $server->approvedProfile['terms']['terms_url']);
        self::assertSame('https://social.example.org/terms', $server->profile['terms']['terms_url']);
        self::assertSame('https://social.example.org/phishing', $server->pendingProfile['terms']['terms_url'], 'the change still waits for a curator');
    }

    public function testADelistingCannotBeEscapedByWithdrawing(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $curation = static::getContainer()->get(CurationService::class);
        // The decision takes effect at once, so there is no window in which the server could withdraw.
        $curation->decide($this->curator('alice@nextcloud.test'), $this->server('social.example.org'), Decision::Delist, ['rule' => 'active_moderation', 'public_reason' => 'Reports went unanswered.'], 'Complaint #1.');
        self::assertSame(ServerState::Delisted, $this->server('social.example.org')->state);
        self::assertCount(1, $this->em->getRepository(TransparencyEntry::class)->findBy(['serverDomain' => 'social.example.org']));

        $this->clock->sleep(1);
        self::assertSame('not_active', $this->api('DELETE', '/api/v1/servers/social.example.org', null, $fake, 409)['error']);
        self::assertSame(ServerState::Delisted, $this->server('social.example.org')->state);
        $this->clock->sleep(86400);
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'social.example.org', 'public_key' => $fake->publicKey()], null, 403);
    }

    public function testStaleWritesFail(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $server = $this->server('social.example.org');
        $this->em->getConnection()->executeStatement('UPDATE server SET version = version + 1 WHERE id = ?', [$server->id]);
        $server->decisionReason = 'Stale write';
        $this->expectException(OptimisticLockException::class);
        $this->em->flush();
    }

    public function testMinuteJobDecidesOnCurrentState(): void
    {
        $this->installKeySet();
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $this->clock->sleep(8 * 86400);

        // The minute job holds a stale copy (listed, no heartbeat for 8 days) while a curator pauses
        // the server in another process. Its automatic pause must not replace the curator's.
        $stale = $this->server('social.example.org');
        self::assertSame(ServerState::Listed, $stale->state);
        $this->em->getConnection()->executeStatement(
            "UPDATE server SET state = 'paused', pause_kind = 'curator', pause_reason = 'Investigation', version = version + 1 WHERE id = ?",
            [$stale->id],
        );
        $this->tick();

        $server = $this->server('social.example.org');
        $this->em->refresh($server);
        self::assertSame(ServerState::Paused, $server->state);
        self::assertSame(PauseKind::Curator, $server->pauseKind);
    }

    public function testListsAreCheckedAgainstTheInstalledKeySet(): void
    {
        // keys.json names a different list key than the one that signs.
        [$rootSecret] = TestKeys::root();
        [, $otherPublic] = TestKeys::random();
        $json = KeySetService::sign([['kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode($otherPublic), 'status' => 'active']], 1, TestKeys::ROOT_KID, $rootSecret, $this->clock->now(), 365);
        static::getContainer()->get(KeySetService::class)->install($json);
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);

        try {
            $this->tick(true);
            self::fail('A list that clients would reject must not be published.');
        } catch (\Throwable) {
        }
        $dir = static::getContainer()->getParameter('kernel.project_dir').'/var/test-output/lists/v1/';
        self::assertFileDoesNotExist($dir.'servers.json');
    }

    public function testAddingAPasskeyNeedsAnExistingOne(): void
    {
        $alice = $this->createCurator('alice@nextcloud.test');
        $accounts = static::getContainer()->get(CuratorAccounts::class);
        $passkeys = static::getContainer()->get(Passkeys::class);
        $session = new Session(new MockArraySessionStorage());
        $new = new SoftAuthenticator();

        // Password and a new passkey, but no existing passkey: refused.
        $create = $passkeys->creationOptions($session, CuratorAccounts::ADD_PASSKEY_PURPOSE, (string) $alice->webauthnUserHandle, $alice->email, $alice->displayName, $alice);
        try {
            $accounts->addPasskey($alice, 'correct horse battery staple', new SecondFactorProof($session, 'confirm', '', ''), $new->create($create), 'Attacker key');
            self::fail('A new passkey without an existing one must be refused.');
        } catch (CurationException) {
        }
        self::assertCount(1, $passkeys->passkeysOf($alice));

        $create = $passkeys->creationOptions($session, CuratorAccounts::ADD_PASSKEY_PURPOSE, (string) $alice->webauthnUserHandle, $alice->email, $alice->displayName, $alice);
        $get = $passkeys->requestOptions($session, 'confirm', $alice);
        $accounts->addPasskey($alice, 'correct horse battery staple', new SecondFactorProof($session, 'confirm', $this->authenticators['alice@nextcloud.test']->get($get), ''), $new->create($create), 'Second key');
        self::assertCount(2, $passkeys->passkeysOf($alice));
    }

    public function testAdminsCannotRemoveTheirOwnConflict(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $alice = $this->createCurator('alice@nextcloud.test', true);
        $carol = $this->createCurator('carol@nextcloud.test', true);
        $curation = static::getContainer()->get(CurationService::class);
        $curation->declareConflict($alice, $alice, $this->server('social.example.org'), 'I run it.');
        $conflict = $this->em->getRepository(CuratorConflict::class)->findOneBy(['curator' => $alice]);
        self::assertNotNull($conflict);

        try {
            $curation->removeConflict($alice, $conflict);
            self::fail('An admin must not remove their own conflict.');
        } catch (CurationException) {
        }
        $curation->removeConflict($carol, $conflict);
        self::assertNull($curation->conflict($this->curator('alice@nextcloud.test'), $this->server('social.example.org')));
    }

    public function testSignInGuessingIsLimitedPerAccountAcrossAddresses(): void
    {
        $alice = $this->createCurator('alice@nextcloud.test');
        static::getContainer()->get(\App\Security\RecoveryCodes::class)->regenerate($alice);
        $this->em->flush();
        for ($i = 0; $i < 20; ++$i) {
            $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.'.intdiv($i, 4));
            $this->consoleLogin('alice@nextcloud.test', recoveryCode: 'AAAA-BBBB-CCCC');
            self::assertResponseStatusCodeSame(401);
        }
        // From yet another address, even the right passkey is refused now.
        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.9');
        $this->consoleLogin('alice@nextcloud.test');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAbuseTestMailsAreThrottledPerServer(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $checker = static::getContainer()->get(Checker::class);
        $checker->run($this->server('social.example.org'));
        self::assertSame('abuse@social.example.org', $this->server('social.example.org')->abuseTestMailSentTo);

        $profile = Fixtures::profile('social.example.org');
        $profile['moderation']['abuse_email'] = 'victim@elsewhere.test';
        $profile['etag'] = 'W/"8"';
        $this->clock->sleep(60);
        $this->api('PUT', '/api/v1/servers/social.example.org/profile', $profile, $fake);
        $checker->run($this->server('social.example.org'));
        self::assertSame('abuse@social.example.org', $this->server('social.example.org')->abuseTestMailSentTo, 'no second test mail within a day');

        $this->clock->sleep(86400);
        $this->heartbeat($fake);
        $checker->run($this->server('social.example.org'));
        self::assertSame('victim@elsewhere.test', $this->server('social.example.org')->abuseTestMailSentTo);
    }

}
