<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Crypto\Base64Url;
use App\Entity\ListBuild;
use App\Entity\TransparencyReport;
use App\Lists\KeySetService;
use App\Service\SystemStateStore;
use App\Tests\Support\ConsoleCommandTestCase;
use App\Tests\Support\TestKeys;
use Symfony\Component\Lock\LockFactory;

/**
 * The commands that publish: app:tick, app:lists:verify, app:site:build and app:transparency:report.
 */
final class PublishingCommandsTest extends ConsoleCommandTestCase
{
    private const SITE_PAGES = ['index.html', 'covenant/index.html', 'criteria/index.html', 'privacy/index.html', 'transparency/index.html', 'keys/index.html'];

    public function testTickPublishesListsOnlyWhenSomethingChanged(): void
    {
        $this->installKeySet();

        // The first tick signs both lists, which do not exist yet, and renders the website. Serials
        // are timestamps.
        $first = $this->clock->now()->getTimestamp();
        [$exit, $output] = $this->console('app:tick');
        self::assertSame(0, $exit);
        self::assertSame("Evaluated 0 servers, processed 0 media, published: servers#{$first}, search#{$first}, website rendered.", $output);
        self::assertSame([], $this->publishedList('servers')['entries']);
        self::assertSame([], array_column($this->publishedList('search')['entries'], 'domain'), 'only the built-in search providers');
        foreach (self::SITE_PAGES as $page) {
            self::assertFileExists($this->outputDir().'/'.$page);
        }
        self::assertNotNull($this->systemState(SystemStateStore::LAST_TICK));

        // Nothing changed: quiet for cron, unless asked.
        $this->clock->sleep(60);
        self::assertSame([0, ''], $this->console('app:tick'));
        self::assertSame([0, 'Evaluated 0 servers, processed 0 media, published: nothing.'], $this->console('app:tick', verbose: true));
        self::assertSame([0, 'Evaluated 0 servers, processed 0 media, published: nothing, website rendered.'], $this->console('app:tick', ['--site' => true], true));
        self::assertCount(2, $this->em->getRepository(ListBuild::class)->findBy(['list' => ['servers', 'search']]));

        // --force re-signs both lists with higher serials, even within the same second.
        $now = $this->clock->now()->getTimestamp();
        self::assertSame([0, "Evaluated 0 servers, processed 0 media, published: servers#{$now}, search#{$now}, website rendered."], $this->console('app:tick', ['--force' => true]));
        self::assertSame([0, \sprintf('Evaluated 0 servers, processed 0 media, published: servers#%d, search#%d, website rendered.', $now + 1, $now + 1)], $this->console('app:tick', ['--force' => true]));
        self::assertSame($now + 1, $this->publishedList('servers')['serial']);
        $builds = $this->em->getRepository(ListBuild::class)->findBy(['list' => 'servers', 'serial' => (string) ($now + 1)]);
        self::assertSame('manual', $builds[0]->reason);
    }

    public function testTickPublishesAListedServerAndSealsTheAuditLog(): void
    {
        $this->installKeySet();
        $this->listServer($this->enrolServer('social.example.org'));
        $this->clock->sleep(60);

        [$exit, $output] = $this->console('app:tick');
        self::assertSame(0, $exit);
        self::assertStringContainsString('Evaluated 1 servers', $output);
        $serial = $this->clock->now()->getTimestamp();
        self::assertStringContainsString("published: servers#{$serial}, search#{$serial}, website rendered.", $output);
        self::assertSame(['social.example.org'], array_column($this->publishedList('servers')['entries'], 'domain'));
        self::assertFileExists($this->outputDir().'/servers/social.example.org/index.html');

        // Every audit entry older than the grace period is chained by the tick.
        $unsealed = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE chain_seq IS NULL AND created_at <= ?', [$this->clock->now()->modify('-30 seconds')->format('Y-m-d H:i:s')]);
        self::assertSame(0, $unsealed);
        self::assertGreaterThan(5, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE chain_seq IS NOT NULL'));
    }

    public function testTickThatFindsAnotherTickRunningIsNotAFailure(): void
    {
        $lock = static::getContainer()->get(LockFactory::class)->createLock('aloha-directory-tick', 300);
        self::assertTrue($lock->acquire());
        try {
            [$exit] = $this->console('app:tick');
            self::assertSame(0, $exit);
            self::assertNull($this->systemState(SystemStateStore::LAST_TICK), 'the skipped run did nothing');
        } finally {
            $lock->release();
        }
    }

    public function testVerifyListAcceptsWhatTheDirectoryPublished(): void
    {
        // app:lists:verify checks against the wall clock, like a client: publish "yesterday".
        $this->clockToWallTime('-1 day');
        $this->installKeySet();
        $this->listServer($this->enrolServer('social.example.org'));
        $this->tick();
        $dir = $this->outputDir().'/lists/v1/';
        $servers = $this->publishedList('servers');
        $search = $this->publishedList('search');

        [$exit, $output] = $this->console('app:lists:verify', ['file' => $dir.'servers.json', '--keyset' => $dir.'keys.json']);
        self::assertSame(0, $exit, $output);
        self::assertSame(\sprintf('[OK] servers.json serial %d, issued %s, 1 entries.', $servers['serial'], $servers['issued_at']), $output);

        [$exit, $output] = $this->console('app:lists:verify', ['file' => $dir.'search.json', '--keyset' => $dir.'keys.json', '--list' => 'search']);
        self::assertSame(0, $exit, $output);
        self::assertSame(\sprintf('[OK] search.json serial %d, issued %s, %d entries.', $search['serial'], $search['issued_at'], \count($search['entries'])), $output);
    }

    public function testVerifyListReportsAnExpiredList(): void
    {
        $this->clockToWallTime('-40 days');
        $this->installKeySet();
        $this->tick();
        $dir = $this->outputDir().'/lists/v1/';

        [$exit, $output] = $this->console('app:lists:verify', ['file' => $dir.'servers.json', '--keyset' => $dir.'keys.json']);
        self::assertSame(0, $exit, 'clients keep using an expired list');
        self::assertStringEndsWith('0 entries (EXPIRED).', $output);
    }

    public function testVerifyListRejectsWhatAClientMustReject(): void
    {
        $this->clockToWallTime('-1 day');
        $this->installKeySet();
        $this->tick();
        $dir = $this->outputDir().'/lists/v1/';
        $scratch = $this->scratchDir();

        [$exit, $output] = $this->console('app:lists:verify', ['file' => $dir.'servers.json']);
        self::assertSame(2, $exit);
        self::assertStringContainsString('--keyset is required.', $output);

        $this->assertVerifyFails($dir.'servers.json', $dir.'keys.json', 'Expected list "search".', 'search');
        $this->assertVerifyFails($dir.'keys.json', $dir.'keys.json', 'No valid signature from a trusted key.');
        $this->assertVerifyFails($scratch.'/missing.json', $dir.'keys.json', 'Envelope is not valid JSON.');
        $this->assertVerifyFails($dir.'servers.json', $scratch.'/missing.json', 'Envelope is not valid JSON.');

        // A list whose payload was changed after signing.
        $envelope = json_decode((string) file_get_contents($dir.'servers.json'), true);
        $payload = json_decode(Base64Url::decode($envelope['payload']), true);
        $payload['entries'][] = ['domain' => 'evil.example.org'];
        $envelope['payload'] = Base64Url::encode((string) json_encode($payload));
        $this->assertVerifyFails($this->scratchFile($scratch, 'servers.json', (string) json_encode($envelope)), $dir.'keys.json', 'No valid signature from a trusted key.');

        // A key set from a root key that is not pinned, even if it names the right list key.
        [, $listPublic] = TestKeys::list();
        $keys = [['kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode($listPublic), 'status' => 'active']];
        $rogue = $this->scratchFile($scratch, 'rogue-keys.json', KeySetService::sign($keys, 99, TestKeys::ROOT_KID, TestKeys::random()[0], $this->clock->now(), 365));
        $this->assertVerifyFails($dir.'servers.json', $rogue, 'No valid signature from a trusted key.');

        // A genuine key set in which the list's signing key is revoked.
        $keys[0]['status'] = 'revoked';
        $keys[] = ['kid' => 'test-list-b', 'public_key' => TestKeys::encode(TestKeys::random()[1]), 'status' => 'active'];
        $revoked = $this->scratchFile($scratch, 'revoked-keys.json', KeySetService::sign($keys, 2, TestKeys::ROOT_KID, TestKeys::root()[0], $this->clock->now(), 365));
        $this->assertVerifyFails($dir.'servers.json', $revoked, 'No valid signature from a trusted key.');
    }

    public function testSiteBuildRendersThePublicWebsite(): void
    {
        [$exit, $output] = $this->console('app:site:build');
        self::assertSame(0, $exit);
        self::assertSame('[OK] 6 pages written.', $output);
        foreach (self::SITE_PAGES as $page) {
            self::assertFileExists($this->outputDir().'/'.$page);
        }

        $this->installKeySet();
        $this->listServer($this->enrolServer('social.example.org'));
        $this->tick();
        // A page of a server that is no longer listed.
        $stale = $this->outputDir().'/servers/gone.example.org/index.html';
        mkdir(\dirname($stale), 0o755, true);
        file_put_contents($stale, 'old');

        self::assertSame([0, '[OK] 7 pages written.'], $this->console('app:site:build'));
        self::assertStringContainsString('Example Social', (string) file_get_contents($this->outputDir().'/servers/social.example.org/index.html'));
        self::assertStringContainsString('Example Social', (string) file_get_contents($this->outputDir().'/index.html'));
        self::assertFileDoesNotExist($stale);
        self::assertStringContainsString(TestKeys::LIST_KID, (string) file_get_contents($this->outputDir().'/keys/index.html'));
    }

    public function testTransparencyReportCountsTheLastFullQuarter(): void
    {
        // Two applications in the third quarter, one of them listed; one more in the fourth.
        $this->clock->modify('2026-08-03 09:00:00 UTC');
        $this->installKeySet();
        $this->listServer($this->enrolServer('a.example.org'));
        $this->enrolServer('b.example.org');
        $this->clock->modify('2026-10-02 09:00:00 UTC');
        $this->enrolServer('c.example.org');
        $this->clock->modify('2026-11-13 12:00:00 UTC');

        [$exit, $output] = $this->console('app:transparency:report');
        self::assertSame(0, $exit);
        self::assertSame('[OK] Transparency report 2026-Q3 generated.', $output, 'the default is the last full quarter');
        $report = $this->report('2026-Q3');
        self::assertSame(2, $report->data['applications']);
        self::assertSame(1, $report->data['entered_review']);
        self::assertSame(1, $report->data['approvals']);
        self::assertSame(0, $report->data['rejections']);
        self::assertSame(0, $report->data['complaints']);
        self::assertStringNotContainsString('a.example.org', (string) json_encode($report->data), 'aggregates only');

        // The website shows it at once.
        $page = (string) file_get_contents($this->outputDir().'/transparency/index.html');
        self::assertStringContainsString('id="report-2026-Q3"', $page);

        // Regenerating replaces the report instead of adding another one.
        $this->clock->sleep(3600);
        self::assertSame([0, '[OK] Transparency report 2026-Q3 generated.'], $this->console('app:transparency:report', ['quarter' => '2026-Q3']));
        self::assertCount(1, $this->em->getRepository(TransparencyReport::class)->findBy(['quarter' => '2026-Q3']));
        self::assertEquals($this->clock->now(), $this->report('2026-Q3')->generatedAt);

        // An earlier quarter has nothing in it.
        self::assertSame(0, $this->console('app:transparency:report', ['quarter' => '2026-Q2'])[0]);
        self::assertSame(0, $this->report('2026-Q2')->data['applications']);
    }

    public function testTransparencyReportRefusesUnfinishedAndMalformedQuarters(): void
    {
        foreach (['2026-Q4' => 'once it has ended', '2027-Q1' => 'once it has ended', '2026-Q5' => 'must look like', '2026Q3' => 'must look like', '' => 'must look like'] as $quarter => $message) {
            try {
                $this->console('app:transparency:report', ['quarter' => $quarter]);
                self::fail('A report was generated for '.$quarter);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertSame([], $this->em->getRepository(TransparencyReport::class)->findAll());

        // On the first day of a quarter, the quarter that just ended is the default.
        $this->clock->modify('2027-01-01 06:00:00 UTC');
        self::assertSame([0, '[OK] Transparency report 2026-Q4 generated.'], $this->console('app:transparency:report'));
    }

    private function assertVerifyFails(string $file, string $keyset, string $message, string $list = 'servers'): void
    {
        [$exit, $output] = $this->console('app:lists:verify', ['file' => $file, '--keyset' => $keyset, '--list' => $list]);
        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('Verification failed: '.$message, $output);
    }

    private function report(string $quarter): TransparencyReport
    {
        $this->em->clear();

        return $this->em->getRepository(TransparencyReport::class)->findOneBy(['quarter' => $quarter]) ?? throw new \LogicException('No report '.$quarter);
    }
}
