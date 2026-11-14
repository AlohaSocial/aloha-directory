<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Complaint;
use App\Entity\ListState;
use App\Entity\Server;
use App\Enum\PauseKind;
use App\Enum\ServerState;
use App\Monitoring\Metrics;
use App\Service\SystemStateStore;
use App\Tests\Support\FunctionalTestCase;

final class MetricsTest extends FunctionalTestCase
{
    private const SAMPLE = '/\A(aloha_directory_[a-z_]+)(\{[a-z_]+="(?:[^"\\\\\n]|\\\\.)*"(?:,[a-z_]+="(?:[^"\\\\\n]|\\\\.)*")*\})? (-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)\z/';

    private bool $messengerTable = false;

    protected function tearDown(): void
    {
        if ($this->messengerTable) {
            $this->em->getConnection()->executeStatement('DROP TABLE messenger_messages');
        }
        parent::tearDown();
    }

    public function testEmptyDirectory(): void
    {
        $m = $this->metrics()->collect();

        self::assertSame(0, $m['free_places_total']);
        self::assertSame(['servers' => null, 'search' => null], $m['list_age_seconds']);
        self::assertSame(['servers' => 0, 'search' => 0], $m['list_held_seconds']);
        self::assertNull($m['last_tick_age_seconds']);
        self::assertSame(0, $m['curator_queue_oldest_seconds']);
        self::assertSame([], $m['queues'], 'no database transport in the tests');
        self::assertTrue($m['audit_chain_ok'], 'not verified yet is not a failure');
        self::assertSame(array_fill_keys(array_map(static fn (ServerState $s): string => $s->value, ServerState::cases()), 0), $m['servers_by_state']);

        $text = $this->metrics()->prometheus();
        self::assertStringContainsString("aloha_directory_list_age_seconds{list=\"servers\"} -1\n", $text, 'never signed');
        self::assertStringContainsString("aloha_directory_job_age_seconds{job=\"tick\"} -1\n", $text);
        self::assertStringContainsString("aloha_directory_audit_chain_ok 1\n", $text);
        self::assertStringContainsString("aloha_directory_queue_waiting_jobs{queue=\"failed\"} 0\n", $text);

        $alarms = implode("\n", $this->metrics()->alarms());
        self::assertStringContainsString('Only 0 free places across listed servers (alarm below 1000).', $alarms);
        self::assertStringContainsString('servers.json has not been signed for ever.', $alarms);
        self::assertStringContainsString('search.json has not been signed for ever.', $alarms);
        self::assertStringContainsString('The minute job (app:tick) has not run', $alarms);
        self::assertStringContainsString('The checker (app:check) has not run', $alarms);
    }

    public function testPrometheusTextIsWellFormed(): void
    {
        $this->populate();
        $families = $this->parse($this->metrics()->prometheus());

        self::assertNotEmpty($families);
        foreach ($families as $name => $family) {
            self::assertSame(1, $family['help'], "{$name}: one HELP line");
            self::assertSame(1, $family['type'], "{$name}: one TYPE line");
            self::assertContains($family['kind'], ['gauge', 'counter'], $name);
            if ('counter' === $family['kind']) {
                self::assertStringEndsWith('_total', $name, 'counters end in _total');
            }
        }
        self::assertSame(['gauge'], array_values(array_unique(array_column(array_filter($families, static fn (array $f): bool => !str_ends_with((string) $f['name'], '_total')), 'kind'))));
    }

    public function testSamplesOfAFamilyAreGrouped(): void
    {
        $this->populate();
        $current = null;
        $done = [];
        foreach (explode("\n", trim($this->metrics()->prometheus())) as $line) {
            $name = 1 === preg_match('/\A# (?:HELP|TYPE) (\S+)/', $line, $m) ? $m[1] : (string) strtok($line, '{ ');
            if ($name !== $current) {
                self::assertArrayNotHasKey($name, $done, "{$name} appears in two separate groups");
                if (null !== $current) {
                    $done[$current] = true;
                }
                $current = $name;
            }
        }
    }

    public function testFiguresReflectTheDatabase(): void
    {
        $this->populate();
        $text = $this->metrics()->prometheus();

        self::assertStringContainsString("aloha_directory_servers{state=\"listed\"} 3\n", $text);
        self::assertStringContainsString("aloha_directory_servers{state=\"paused\"} 2\n", $text);
        self::assertStringContainsString("aloha_directory_servers{state=\"in_review\"} 1\n", $text);
        self::assertStringContainsString("aloha_directory_servers{state=\"rejected\"} 0\n", $text);
        // Only listed servers that are not full count: 300 + 250; the closed one and the one without figures do not.
        self::assertStringContainsString("aloha_directory_servers_with_free_places 2\n", $text);
        self::assertStringContainsString("aloha_directory_free_places 550\n", $text);
        self::assertStringContainsString("aloha_directory_list_age_seconds{list=\"servers\"} 1800\n", $text);
        self::assertStringContainsString("aloha_directory_list_age_seconds{list=\"search\"} 172800\n", $text);
        self::assertStringContainsString("aloha_directory_list_change_pending_seconds{list=\"servers\"} 900\n", $text);
        self::assertStringContainsString("aloha_directory_list_change_pending_seconds{list=\"search\"} 0\n", $text, 'a held list is not counted as pending');
        self::assertStringContainsString("aloha_directory_list_held_seconds{list=\"search\"} 3600\n", $text);
        self::assertStringContainsString("aloha_directory_curator_queue_oldest_seconds 10800\n", $text, 'the open complaint from 3 hours ago');
        self::assertStringContainsString("aloha_directory_list_verification_failures_total{list=\"servers\",reason=\"signature\"} 3\n", $text);
        self::assertStringContainsString("aloha_directory_list_verification_failures_total{list=\"search\",reason=\"serial\"} 1\n", $text);
        self::assertStringNotContainsString('other_counter', $text, 'unrelated counters are not exported as list failures');
        self::assertStringContainsString("aloha_directory_job_age_seconds{job=\"tick\"} 120\n", $text);
        self::assertStringContainsString("aloha_directory_newcomer_concentration_hhi 0.42\n", $text);
        self::assertStringContainsString("aloha_directory_newcomer_top_share 0.6\n", $text);
        self::assertStringContainsString("aloha_directory_queue_waiting_jobs{queue=\"verify\"} 2\n", $text);
        self::assertStringContainsString("aloha_directory_queue_oldest_job_seconds{queue=\"verify\"} 1200\n", $text);
        self::assertStringContainsString("aloha_directory_queue_waiting_jobs{queue=\"failed\"} 2\n", $text);
        self::assertStringContainsString("aloha_directory_queue_waiting_jobs{queue=\"checks\"} 0\n", $text, 'delivered jobs are not waiting');
        self::assertStringContainsString("aloha_directory_audit_chain_ok 0\n", $text);
    }

    public function testAlarms(): void
    {
        $this->populate();
        $alarms = $this->metrics()->alarms();

        self::assertContains('Only 550 free places across listed servers (alarm below 1000).', $alarms);
        self::assertContains('search.json has not been signed for 48 hours.', $alarms);
        self::assertContains('A change to servers.json has waited 15 minutes for a rebuild.', $alarms);
        self::assertContains('search.json has been held by the change guard for 60 minutes; a curator must release it.', $alarms);
        self::assertContains('The oldest job in the verify queue has waited 20 minutes. Is a worker (messenger:consume) running?', $alarms);
        self::assertContains('2 background jobs failed (bin/console messenger:failed:show).', $alarms);
        self::assertContains('One server took 60% of all new accounts on featured servers in the last 7 days.', $alarms);
        self::assertContains('The audit hash chain does not verify: entry 17 does not match', $alarms);
        self::assertContains('The checker (app:check) has not run for more than 3 hours.', $alarms);
        foreach ($alarms as $alarm) {
            self::assertStringNotContainsString('servers.json has not been signed', $alarm, 'signed 30 minutes ago');
            self::assertStringNotContainsString('app:tick', $alarm, 'ticked 2 minutes ago');
            self::assertStringNotContainsString('checks queue', $alarm);
        }
    }

    public function testThresholdsAreExclusive(): void
    {
        $now = $this->clock->now();
        $state = $this->store();
        $state->set(SystemStateStore::LAST_TICK, 'x');
        $state->set(SystemStateStore::LAST_CHECK_RUN, 'x');
        foreach (['servers', 'search'] as $name) {
            $list = new ListState($name);
            $list->lastBuiltAt = $now->modify('-26 hours');
            $list->changePendingSince = $now->modify('-10 minutes');
            $this->em->persist($list);
        }
        $state->set(SystemStateStore::FAIRNESS, (string) json_encode(['featured' => 3, 'total_new_accounts' => 50, 'top_share' => 0.5, 'hhi' => 0.3]));
        $server = $this->newServer('big.example.org', ServerState::Listed, 1000);
        $this->em->persist($server);
        $this->em->flush();
        $this->clock->sleep(600);

        $alarms = $this->metrics()->alarms();
        // tick exactly 600 s ago, list exactly 26 h + 10 min old (alarm), change pending exactly 20 min (alarm),
        // 1000 free places (no alarm), top share exactly 50 % (no alarm).
        self::assertSame([
            'servers.json has not been signed for 26.2 hours.',
            'search.json has not been signed for 26.2 hours.',
            'A change to servers.json has waited 20 minutes for a rebuild.',
            'A change to search.json has waited 20 minutes for a rebuild.',
        ], $alarms);
    }

    private function populate(): void
    {
        // The tick ran two minutes before "now"; everything else is relative to "now".
        $this->store()->set(SystemStateStore::LAST_TICK, 'x');
        $this->em->flush();
        $this->clock->sleep(120);
        $now = $this->clock->now();
        foreach ([['a.example.org', 300, true], ['b.example.org', 250, null], ['c.example.org', 900, false], ['d.example.org', null, true]] as [$domain, $free, $open]) {
            $server = $this->newServer($domain, ServerState::Listed, $free);
            $server->signupOpen = $open;
            if ('c.example.org' === $domain) {
                $server->state = ServerState::Paused; // closed signup and paused: neither listed nor counted
                $server->pauseKind = PauseKind::Checks;
                $server->pausedAt = $now->modify('-2 hours');
            }
            $this->em->persist($server);
        }
        $review = $this->newServer('r.example.org', ServerState::InReview, null);
        $review->inReviewAt = $now->modify('-1 hour');
        $this->em->persist($review);
        $curatorPause = $this->newServer('p.example.org', ServerState::Paused, null);
        $curatorPause->pauseKind = PauseKind::Curator;
        $curatorPause->pausedAt = $now->modify('-30 days');
        $this->em->persist($curatorPause);
        $this->em->persist(new Complaint($review, 'spam', 'Spam.', null, $now->modify('-3 hours')));

        $servers = new ListState('servers');
        $servers->lastBuiltAt = $now->modify('-30 minutes');
        $servers->changePendingSince = $now->modify('-15 minutes');
        $search = new ListState('search');
        $search->lastBuiltAt = $now->modify('-48 hours');
        $search->changePendingSince = $now->modify('-2 hours');
        $search->heldSince = $now->modify('-1 hour');
        $this->em->persist($servers);
        $this->em->persist($search);

        $state = $this->store();
        $state->set(SystemStateStore::FAIRNESS, (string) json_encode(['featured' => 4, 'total_new_accounts' => 200, 'top_share' => 0.6, 'hhi' => 0.42, 'window_days' => 7]));
        $state->set(SystemStateStore::AUDIT_VERIFICATION, (string) json_encode(['ok' => false, 'error' => 'entry 17 does not match']));
        $this->em->flush();
        for ($i = 0; $i < 3; ++$i) {
            $state->increment('list_failure.servers.signature');
        }
        $state->increment('list_failure.search.serial');
        $state->increment('other_counter');

        $db = $this->em->getConnection();
        $db->executeStatement('CREATE TABLE messenger_messages (id INTEGER NOT NULL PRIMARY KEY, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->messengerTable = true;
        $at = static fn (string $modify): string => $now->modify($modify)->format('Y-m-d H:i:s');
        foreach ([['verify', '-20 minutes', null], ['verify', '-5 minutes', null], ['checks', '-5 hours', $at('-4 hours')], ['failed', '-1 day', null], ['failed', '-2 days', null]] as $id => [$queue, $available, $delivered]) {
            $db->insert('messenger_messages', ['id' => $id + 1, 'body' => 'x', 'headers' => '[]', 'queue_name' => $queue, 'created_at' => $at($available), 'available_at' => $at($available), 'delivered_at' => $delivered]);
        }
    }

    private function newServer(string $domain, ServerState $state, ?int $free): Server
    {
        $server = new Server($domain, $this->clock->now()->modify('-60 days'));
        $server->state = $state;
        $server->availableAccounts = $free;

        return $server;
    }

    /**
     * @return array<string, array{name: string, help: int, type: int, kind: ?string, samples: int}>
     */
    private function parse(string $text): array
    {
        self::assertStringEndsWith("\n", $text);
        self::assertStringNotContainsString("\n\n", $text);
        $families = [];
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if (1 === preg_match('/\A# (HELP|TYPE) (aloha_directory_[a-z_]+) (.+)\z/', $line, $m)) {
                $families[$m[2]] ??= ['name' => $m[2], 'help' => 0, 'type' => 0, 'kind' => null, 'samples' => 0];
                if ('HELP' === $m[1]) {
                    ++$families[$m[2]]['help'];
                } else {
                    self::assertSame(0, $families[$m[2]]['samples'], "TYPE of {$m[2]} comes before its samples");
                    ++$families[$m[2]]['type'];
                    $families[$m[2]]['kind'] = $m[3];
                }
                continue;
            }
            self::assertMatchesRegularExpression(self::SAMPLE, $line);
            preg_match(self::SAMPLE, $line, $m);
            self::assertArrayHasKey($m[1], $families, "sample of undeclared metric: {$line}");
            ++$families[$m[1]]['samples'];
        }

        return $families;
    }

    private function metrics(): Metrics
    {
        return static::getContainer()->get(Metrics::class);
    }

    private function store(): SystemStateStore
    {
        return static::getContainer()->get(SystemStateStore::class);
    }
}
