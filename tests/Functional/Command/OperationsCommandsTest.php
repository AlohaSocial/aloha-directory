<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Audit\AuditChain;
use App\Entity\AuditHead;
use App\Entity\Server;
use App\Entity\ServerCheck;
use App\Service\SystemStateStore;
use App\Tests\Support\ConsoleCommandTestCase;
use App\Tests\Support\TestQueueTransport;

/**
 * The cron and monitoring commands: app:check, app:maintenance, app:monitor and app:audit:verify.
 */
final class OperationsCommandsTest extends ConsoleCommandTestCase
{
    public function testAuditVerifyOnAnEmptyLog(): void
    {
        [$exit, $output] = $this->console('app:audit:verify');
        self::assertSame(0, $exit);
        self::assertSame('[OK] 0 entries verify; 0 published heads match. Head: entry 0, '.AuditChain::GENESIS.'.', $output);
    }

    public function testAuditVerifySealsOnRequestAndReportsTheHead(): void
    {
        $this->enrolServer('a.example.org');
        $entries = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log');
        self::assertGreaterThan(2, $entries);

        // Without --seal only verifies: nothing is chained yet.
        self::assertSame([0, '[OK] 0 entries verify; 0 published heads match. Head: entry 0, '.AuditChain::GENESIS.'.'], $this->console('app:audit:verify'));

        // --seal chains everything, even entries younger than the grace period.
        [$exit, $output] = $this->console('app:audit:verify', ['--seal' => true]);
        self::assertSame(0, $exit);
        $head = static::getContainer()->get(AuditChain::class)->head();
        self::assertSame($entries, $head['seq']);
        self::assertSame(\sprintf('Sealed %d entries. [OK] %d entries verify; 0 published heads match. Head: entry %d, %s.', $entries, $entries, $entries, $head['hash']), $output);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE chain_seq IS NULL'));

        $state = json_decode((string) $this->systemState(SystemStateStore::AUDIT_VERIFICATION)?->value, true);
        self::assertTrue($state['ok']);
        self::assertSame($entries, $state['entries']);
    }

    public function testAuditVerifyChecksThePublishedHeads(): void
    {
        $this->installKeySet();
        $this->enrolServer('a.example.org');
        $this->clock->sleep(60);
        self::assertSame(0, $this->console('app:maintenance')[0]);
        $head = static::getContainer()->get(AuditChain::class)->head();

        [$exit, $output] = $this->console('app:audit:verify');
        self::assertSame(0, $exit, $output);
        self::assertSame(\sprintf('[OK] %d entries verify; 1 published heads match. Head: entry %d, %s.', $head['seq'], $head['seq'], $head['hash']), $output);

        // The head file on the website is the record outsiders keep: changing it is detected.
        $file = $this->outputDir().'/transparency/audit/'.$this->clock->now()->format('Y-m-d').'.json';
        self::assertFileExists($file);
        $original = (string) file_get_contents($file);
        file_put_contents($file, str_replace('"payload"', '"payload" ', $original));
        [$exit, $output] = $this->console('app:audit:verify');
        self::assertSame(1, $exit);
        self::assertStringContainsString('in the database differs from the published file', $output);
        file_put_contents($file, $original);

        // Removing the newest entries leaves a consistent but shorter chain: the head gives it away.
        $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE chain_seq >= ?', [$head['seq'] - 1]);
        [$exit, $output] = $this->console('app:audit:verify');
        self::assertSame(1, $exit);
        self::assertStringContainsString(\sprintf('[ERROR] A published head names entry %d, but the chain ends at %d: entries were deleted.', $head['seq'], $head['seq'] - 2), $output);
    }

    public function testAuditVerifyFailsOnATamperedChainAndAlertsTheCurators(): void
    {
        $this->createCurator('alice@nextcloud.test', true);
        $this->enrolServer('a.example.org');
        $this->console('app:audit:verify', ['--seal' => true]);
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM audit_log WHERE chain_seq = 2');

        $this->em->getConnection()->executeStatement("UPDATE audit_log SET actor_label = 'someone else' WHERE chain_seq = 2");
        [$exit, $output] = $this->console('app:audit:verify');
        self::assertSame(1, $exit);
        self::assertSame(\sprintf('[ERROR] Entry 2 (id %s) does not match its hash: it was changed, or an earlier entry was.', $id), $output);

        $state = json_decode((string) $this->systemState(SystemStateStore::AUDIT_VERIFICATION)?->value, true);
        self::assertFalse($state['ok']);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'alice@nextcloud.test');
        self::assertEmailHeaderSame($mail, 'Subject', '[Aloha Directory] ALERT: the audit log was changed');

        // app:monitor keeps raising the alarm until the chain verifies again.
        [$exit, $output] = $this->console('app:monitor');
        self::assertSame(1, $exit);
        self::assertStringContainsString('The audit hash chain does not verify: Entry 2', $output);
    }

    public function testMaintenanceAppliesRetentionAndPublishesTheAuditHead(): void
    {
        $this->installKeySet();
        $this->enrolServer('a.example.org');
        // An enrolment that was never verified, and an invitation nobody used.
        $fake = $this->fake('stale.example.org');
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'stale.example.org', 'public_key' => $fake->publicKey()], null, 201);
        $this->console('app:curator:invite', ['email' => 'nora@nextcloud.test', 'name' => 'Nora']);

        // Quiet for cron.
        self::assertSame([0, ''], $this->console('app:maintenance'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_enrolment WHERE domain = ?', ['stale.example.org']));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM curator_invite'));

        $this->clock->sleep((72 + 31 * 24) * 3600);
        [$exit, $output] = $this->console('app:maintenance', verbose: true);
        self::assertSame(0, $exit);
        self::assertStringContainsString('enrolments_expired 1 ', $output);
        self::assertStringContainsString('invites_purged 1 ', $output);
        self::assertStringContainsString('heartbeats_aggregated 0 ', $output);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pending_enrolment WHERE domain = ?', ['stale.example.org']));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM curator_invite'));

        // Each run is audited and recorded, the whole log is sealed, and one head is published per day.
        $runs = $this->auditEntries('maintenance.run');
        self::assertCount(2, $runs);
        self::assertSame(1, $runs[1]->details['enrolments_expired']);
        self::assertSame(1, $runs[1]->details['invites_purged']);
        self::assertSame($this->clock->now()->format(\DATE_ATOM), $this->systemState(SystemStateStore::LAST_MAINTENANCE)?->value);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE chain_seq IS NULL'));
        self::assertCount(2, $this->em->getRepository(AuditHead::class)->findAll());
        self::assertFileExists($this->outputDir().'/transparency/audit/'.$this->clock->now()->format('Y-m-d').'.json');
        $verification = json_decode((string) $this->systemState(SystemStateStore::AUDIT_VERIFICATION)?->value, true);
        self::assertTrue($verification['ok']);
        self::assertSame(2, $verification['heads_checked']);
    }

    public function testMonitorRaisesEveryAlarmOnAFreshInstallation(): void
    {
        [$exit, $output] = $this->console('app:monitor');
        self::assertSame(1, $exit);
        self::assertStringStartsWith('[ERROR]', $output);
        foreach ([
            'Only 0 free places across listed servers (alarm below 1000).',
            'servers.json has not been signed for ever.',
            'search.json has not been signed for ever.',
            'The minute job (app:tick) has not run for more than 10 minutes.',
            'The checker (app:check) has not run for more than 3 hours.',
        ] as $alarm) {
            self::assertStringContainsString($alarm, $output);
        }
    }

    public function testMonitorIsQuietWhenEverythingRunsAndAlarmsWhenAJobStops(): void
    {
        $this->installKeySet();
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $this->clock->sleep(3600);
        $this->heartbeat($fake, available: 1200);
        $this->console('app:check');
        $this->runJobs();
        $this->clock->sleep(60);
        $this->console('app:tick');

        self::assertSame([0, '[OK] No alarms.'], $this->console('app:monitor'));

        // The minute job stops.
        $this->clock->sleep(11 * 60);
        [$exit, $output] = $this->console('app:monitor');
        self::assertSame(1, $exit);
        self::assertStringContainsString('The minute job (app:tick) has not run for more than 10 minutes.', $output);
        self::assertStringNotContainsString('app:check', $output);
        $this->console('app:tick');
        self::assertSame([0, '[OK] No alarms.'], $this->console('app:monitor'));

        // The checker stops (the minute job keeps running).
        for ($i = 0; $i < 4; ++$i) {
            $this->clock->sleep(3600);
            $this->heartbeat($fake, available: 1200);
            $this->console('app:tick');
        }
        [$exit, $output] = $this->console('app:monitor');
        self::assertSame(1, $exit);
        self::assertSame('[ERROR] The checker (app:check) has not run for more than 3 hours.', $output);
        self::assertSame(0, $this->console('app:check', ['--sync' => true])[0]);
        self::assertSame([0, '[OK] No alarms.'], $this->console('app:monitor'));

        // Too few free places.
        $this->clock->sleep(3600);
        $this->heartbeat($fake, available: 10);
        $this->console('app:tick');
        [$exit, $output] = $this->console('app:monitor');
        self::assertSame(1, $exit);
        self::assertStringContainsString('Only 10 free places across listed servers (alarm below 1000).', $output);
    }

    public function testCheckOneDomainRunsInProcess(): void
    {
        $this->enrolServer('social.example.org');

        [$exit, $output] = $this->console('app:check', ['--domain' => 'unknown.example.org']);
        self::assertSame(1, $exit);
        self::assertSame('[ERROR] Unknown server.', $output);

        $tester = $this->tester('app:check');
        self::assertSame(0, $tester->execute(['--domain' => ' Social.Example.ORG ']));
        $lines = explode("\n", trim($tester->getDisplay()));
        self::assertCount(8, $lines, $tester->getDisplay());
        self::assertMatchesRegularExpression('/^✔ profile\s+Profile received\.$/m', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^✔ blocklist\s+Not on any block list\.$/m', $tester->getDisplay());
        self::assertCount(8, $this->checksOf('social.example.org'));
        self::assertSame(0, $this->queuedChecks(), 'nothing is queued');

        // A block on the parent domain shows up at once.
        $file = $this->scratchFile($this->scratchDir(), 'tier0.txt', "example.org\n");
        $this->console('app:blocklist:import', ['source' => 'oliphant-tier0', 'file' => $file]);
        $tester->execute(['--domain' => 'social.example.org']);
        self::assertMatchesRegularExpression('/^✘ blocklist\s+Blocked by "oliphant-tier0" \(example\.org\): Imported from oliphant-tier0$/m', $tester->getDisplay());
        $blocklist = array_values(array_filter($this->checksOf('social.example.org'), static fn (ServerCheck $c): bool => 'blocklist' === $c->name));
        self::assertFalse($blocklist[0]->passed);
    }

    public function testCheckQueuesOneJobPerActiveServer(): void
    {
        $this->enrolServer('a.example.org');
        $this->enrolServer('b.example.org');
        $this->runJobs();

        [$exit, $output] = $this->console('app:check', verbose: true);
        self::assertSame(0, $exit);
        self::assertSame('Queued 2 servers.', $output);
        self::assertSame(2, $this->queuedChecks());
        self::assertNull($this->systemState(SystemStateStore::LAST_CHECK_RUN), 'queueing is not checking');

        // The workers are behind: no duplicates.
        [$exit, $output] = $this->console('app:check', verbose: true);
        self::assertSame(0, $exit);
        self::assertStringContainsString('[WARNING] 2 checks from the previous run are still waiting. Add workers (messenger:consume checks).', $output);
        self::assertSame(2, $this->queuedChecks());

        self::assertSame(2, $this->runJobs());
        self::assertNotNull($this->systemState(SystemStateStore::LAST_CHECK_RUN));
        self::assertCount(8, $this->checksOf('a.example.org'));
        self::assertCount(8, $this->checksOf('b.example.org'));
    }

    public function testCheckSyncChecksEveryServerInProcess(): void
    {
        $this->enrolServer('a.example.org');
        $this->enrolServer('b.example.org');
        $this->runJobs();
        // Jobs still waiting do not stop a synchronous run.
        $this->console('app:check');

        [$exit, $output] = $this->console('app:check', ['--sync' => true], true);
        self::assertSame(0, $exit);
        self::assertSame('Checked 2 servers, 0 failures.', $output);
        self::assertSame(2, $this->queuedChecks(), 'nothing more was queued');
        self::assertCount(8, $this->checksOf('a.example.org'));
        self::assertCount(8, $this->checksOf('b.example.org'));
        self::assertSame($this->clock->now()->getTimestamp(), $this->systemState(SystemStateStore::LAST_CHECK_RUN)?->updatedAt->getTimestamp());

        // Servers that left are not checked any more.
        $this->em->getConnection()->executeStatement("UPDATE server SET state = 'withdrawn' WHERE domain = 'b.example.org'");
        self::assertSame([0, 'Checked 1 servers, 0 failures.'], $this->console('app:check', ['--sync' => true], true));
    }

    private function queuedChecks(): int
    {
        $transport = static::getContainer()->get('messenger.transport.checks');
        \assert($transport instanceof TestQueueTransport);

        return $transport->getMessageCount();
    }

    /**
     * @return list<ServerCheck>
     */
    private function checksOf(string $domain): array
    {
        $this->em->clear();
        $server = $this->em->getRepository(Server::class)->findOneBy(['domain' => $domain]);
        self::assertNotNull($server);

        return $this->em->getRepository(ServerCheck::class)->findBy(['server' => $server]);
    }
}
