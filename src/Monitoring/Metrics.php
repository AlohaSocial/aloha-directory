<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Audit\AuditChain;
use App\Entity\ListState;
use App\Enum\ServerState;
use App\Lists\ListBuilder;
use App\Repository\ComplaintRepository;
use App\Repository\HeartbeatRepository;
use App\Repository\ServerRepository;
use App\Service\DirectorySettings;
use App\Service\SystemStateStore;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The figures of concept 13.8, as Prometheus metrics and as alarms for app:monitor.
 */
final class Metrics
{
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly HeartbeatRepository $heartbeats,
        private readonly ComplaintRepository $complaints,
        private readonly SystemStateStore $state,
        private readonly EntityManagerInterface $em,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
        private readonly AuditChain $auditChain,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $now = $this->clock->now();
        $listed = $this->servers->findInStates(ServerState::Listed);
        $freePlaces = 0;
        $withPlaces = 0;
        foreach ($listed as $server) {
            if (!$server->isFull() && null !== $server->availableAccounts) {
                $freePlaces += $server->availableAccounts;
                ++$withPlaces;
            }
        }

        $listAges = [];
        $pendingChanges = [];
        $held = [];
        foreach (ListBuilder::SIGNED_LISTS as $list) {
            $state = $this->em->find(ListState::class, $list->value);
            $listAges[$list->value] = null === $state?->lastBuiltAt ? null : $now->getTimestamp() - $state->lastBuiltAt->getTimestamp();
            $held[$list->value] = null === $state?->heldSince ? 0 : $now->getTimestamp() - $state->heldSince->getTimestamp();
            $pendingChanges[$list->value] = null === $state?->changePendingSince || null !== $state->heldSince ? 0 : $now->getTimestamp() - $state->changePendingSince->getTimestamp();
        }

        $queue = [...$this->servers->findInReview(), ...$this->servers->findPaused(true)];
        $oldest = null;
        foreach ($queue as $server) {
            $since = $server->inReviewAt ?? $server->pausedAt;
            if (null !== $since && (null === $oldest || $since < $oldest)) {
                $oldest = $since;
            }
        }
        foreach ($this->complaints->findOpen() as $complaint) {
            if (null === $oldest || $complaint->createdAt < $oldest) {
                $oldest = $complaint->createdAt;
            }
        }

        return [
            'servers_by_state' => $this->servers->countByState(),
            'servers_with_free_places' => $withPlaces,
            'free_places_total' => $freePlaces,
            'heartbeats_last_hour' => $this->heartbeats->countSince($now->modify('-1 hour')),
            'list_age_seconds' => $listAges,
            'list_change_pending_seconds' => $pendingChanges,
            'list_held_seconds' => $held,
            'curator_queue_oldest_seconds' => null === $oldest ? 0 : $now->getTimestamp() - $oldest->getTimestamp(),
            'counters' => $this->state->counters(),
            'last_tick_age_seconds' => $this->ageOf(SystemStateStore::LAST_TICK),
            'last_check_age_seconds' => $this->ageOf(SystemStateStore::LAST_CHECK_RUN),
            'fairness' => $this->stateJson(SystemStateStore::FAIRNESS),
            'queues' => $this->queues(),
            'audit_unsealed' => $this->auditChain->backlog(),
            'audit_chain_ok' => false !== ($this->auditVerification()['ok'] ?? true),
        ];
    }

    /**
     * @return list<string>
     */
    public function alarms(): array
    {
        $m = $this->collect();
        $alarms = [];
        if ($m['free_places_total'] < $this->settings->int('alarm_min_free_places')) {
            $alarms[] = \sprintf('Only %d free places across listed servers (alarm below %d).', $m['free_places_total'], $this->settings->int('alarm_min_free_places'));
        }
        foreach ($m['list_age_seconds'] as $list => $age) {
            if (null === $age || $age > 3600 * $this->settings->int('alarm_list_age_hours')) {
                $alarms[] = \sprintf('%s.json has not been signed for %s.', $list, null === $age ? 'ever' : round($age / 3600, 1).' hours');
            }
        }
        foreach ($m['list_change_pending_seconds'] as $list => $age) {
            if ($age > 60 * $this->settings->int('alarm_change_pending_minutes')) {
                $alarms[] = \sprintf('A change to %s.json has waited %d minutes for a rebuild.', $list, intdiv($age, 60));
            }
        }
        foreach ($m['list_held_seconds'] as $list => $age) {
            if ($age > 0) {
                $alarms[] = \sprintf('%s.json has been held by the change guard for %d minutes; a curator must release it.', $list, intdiv($age, 60));
            }
        }
        if (null === $m['last_tick_age_seconds'] || $m['last_tick_age_seconds'] > 600) {
            $alarms[] = 'The minute job (app:tick) has not run for more than 10 minutes.';
        }
        if (null === $m['last_check_age_seconds'] || $m['last_check_age_seconds'] > 3 * 3600) {
            $alarms[] = 'The checker (app:check) has not run for more than 3 hours.';
        }

        foreach (['verify' => 600, 'checks' => 7200] as $queue => $limit) {
            $age = $m['queues'][$queue]['oldest_seconds'] ?? 0;
            if ($age > $limit) {
                $alarms[] = \sprintf('The oldest job in the %s queue has waited %d minutes. Is a worker (messenger:consume) running?', $queue, intdiv($age, 60));
            }
        }
        if (($m['queues']['failed']['waiting'] ?? 0) > 0) {
            $alarms[] = \sprintf('%d background jobs failed (bin/console messenger:failed:show).', $m['queues']['failed']['waiting']);
        }
        $fairness = $m['fairness'];
        if (($fairness['featured'] ?? 0) >= 3 && ($fairness['total_new_accounts'] ?? 0) >= $this->settings->int('fairness_min_new_accounts')
            && 100 * ($fairness['top_share'] ?? 0) > $this->settings->int('fairness_alarm_top_share_percent')) {
            $alarms[] = \sprintf('One server took %d%% of all new accounts on featured servers in the last %d days.', (int) round(100 * $fairness['top_share']), $fairness['window_days'] ?? 7);
        }
        if ($m['audit_unsealed'] > 0) {
            $alarms[] = \sprintf('%d audit log entries are older than 10 minutes and not chained.', $m['audit_unsealed']);
        }
        if (!$m['audit_chain_ok']) {
            $alarms[] = 'The audit hash chain does not verify: '.($this->auditVerification()['error'] ?? 'unknown error');
        }

        return $alarms;
    }

    /**
     * Waiting jobs per queue of the database transport.
     *
     * @return array<string, array{waiting: int, oldest_seconds: int}>
     */
    private function queues(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $result = [];
        try {
            $rows = $this->em->getConnection()->fetchAllAssociative(
                'SELECT queue_name, COUNT(*) AS waiting, MIN(available_at) AS oldest FROM messenger_messages WHERE delivered_at IS NULL GROUP BY queue_name',
            );
        } catch (\Doctrine\DBAL\Exception) {
            return []; // no database transport (tests) or table not created yet
        }
        foreach ($rows as $row) {
            $oldest = strtotime((string) $row['oldest'].' UTC');
            $result[(string) $row['queue_name']] = ['waiting' => (int) $row['waiting'], 'oldest_seconds' => false === $oldest ? 0 : max(0, $now - $oldest)];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditVerification(): array
    {
        return $this->stateJson(SystemStateStore::AUDIT_VERIFICATION);
    }

    /**
     * @return array<string, mixed>
     */
    private function stateJson(string $name): array
    {
        $value = json_decode((string) $this->state->get($name)?->value, true);

        return \is_array($value) ? $value : [];
    }

    public function prometheus(): string
    {
        $m = $this->collect();
        $out = [];
        $metric = static function (string $name, string $help, string $type) use (&$out): void {
            $out[] = "# HELP aloha_directory_{$name} {$help}";
            $out[] = "# TYPE aloha_directory_{$name} {$type}";
        };
        $label = static fn (string $v): string => str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $v);

        $metric('servers', 'Servers by listing state.', 'gauge');
        foreach ($m['servers_by_state'] as $state => $n) {
            $out[] = \sprintf('aloha_directory_servers{state="%s"} %d', $label($state), $n);
        }
        $metric('servers_with_free_places', 'Listed servers with free accounts.', 'gauge');
        $out[] = 'aloha_directory_servers_with_free_places '.$m['servers_with_free_places'];
        $metric('free_places', 'Free accounts across listed servers.', 'gauge');
        $out[] = 'aloha_directory_free_places '.$m['free_places_total'];
        $metric('heartbeats_last_hour', 'Accepted heartbeats in the last hour.', 'gauge');
        $out[] = 'aloha_directory_heartbeats_last_hour '.$m['heartbeats_last_hour'];
        $metric('list_age_seconds', 'Seconds since each list was last signed.', 'gauge');
        foreach ($m['list_age_seconds'] as $list => $age) {
            $out[] = \sprintf('aloha_directory_list_age_seconds{list="%s"} %d', $list, $age ?? -1);
        }
        $metric('list_change_pending_seconds', 'Seconds a change has waited for a rebuild.', 'gauge');
        foreach ($m['list_change_pending_seconds'] as $list => $age) {
            $out[] = \sprintf('aloha_directory_list_change_pending_seconds{list="%s"} %d', $list, $age);
        }
        $metric('list_held_seconds', 'Seconds a build has been held by the change guard (0 when not held).', 'gauge');
        foreach ($m['list_held_seconds'] as $list => $age) {
            $out[] = \sprintf('aloha_directory_list_held_seconds{list="%s"} %d', $list, $age);
        }
        $metric('curator_queue_oldest_seconds', 'Age of the oldest item in the curator queue.', 'gauge');
        $out[] = 'aloha_directory_curator_queue_oldest_seconds '.$m['curator_queue_oldest_seconds'];
        $metric('list_verification_failures_total', 'List verification failures reported by clients.', 'counter');
        foreach ($m['counters'] as $name => $value) {
            if (1 === preg_match('/\Alist_failure\.([a-z]+)\.([a-z]+)\z/', $name, $mm)) {
                $out[] = \sprintf('aloha_directory_list_verification_failures_total{list="%s",reason="%s"} %d', $mm[1], $mm[2], $value);
            }
        }
        $metric('job_age_seconds', 'Seconds since the cron jobs last completed.', 'gauge');
        $out[] = \sprintf('aloha_directory_job_age_seconds{job="tick"} %d', $m['last_tick_age_seconds'] ?? -1);
        $out[] = \sprintf('aloha_directory_job_age_seconds{job="check"} %d', $m['last_check_age_seconds'] ?? -1);

        $metric('newcomer_concentration_hhi', 'Herfindahl index of new accounts across featured servers (7 days; 1 = one server takes all).', 'gauge');
        $out[] = 'aloha_directory_newcomer_concentration_hhi '.($m['fairness']['hhi'] ?? 0);
        $metric('newcomer_top_share', 'Share of new accounts taken by the largest featured server (7 days).', 'gauge');
        $out[] = 'aloha_directory_newcomer_top_share '.($m['fairness']['top_share'] ?? 0);
        $metric('queue_waiting_jobs', 'Background jobs waiting per queue.', 'gauge');
        foreach (['verify', 'checks', 'failed'] as $queue) {
            $out[] = \sprintf('aloha_directory_queue_waiting_jobs{queue="%s"} %d', $queue, $m['queues'][$queue]['waiting'] ?? 0);
        }
        $metric('queue_oldest_job_seconds', 'Age of the oldest waiting job per queue.', 'gauge');
        foreach (['verify', 'checks', 'failed'] as $queue) {
            $out[] = \sprintf('aloha_directory_queue_oldest_job_seconds{queue="%s"} %d', $queue, $m['queues'][$queue]['oldest_seconds'] ?? 0);
        }
        $metric('audit_unsealed_entries', 'Audit entries older than 10 minutes that are not chained yet.', 'gauge');
        $out[] = 'aloha_directory_audit_unsealed_entries '.$m['audit_unsealed'];
        $metric('audit_chain_ok', 'Whether the last verification of the audit hash chain succeeded.', 'gauge');
        $out[] = 'aloha_directory_audit_chain_ok '.($m['audit_chain_ok'] ? 1 : 0);

        return implode("\n", $out)."\n";
    }

    private function ageOf(string $name): ?int
    {
        $state = $this->state->get($name);

        return null === $state ? null : $this->clock->now()->getTimestamp() - $state->updatedAt->getTimestamp();
    }
}
