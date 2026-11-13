<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Entity\Server;
use App\Enum\ServerState;
use App\Repository\ServerRepository;
use App\Service\AuditLogger;
use App\Service\DirectorySettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Keeps newcomers spread across servers (concept 9.2, goal G3).
 *
 * The suggestion is made on each device from the signed list, and the directory never sees a
 * signup. What it does see is the account total each server reports in its heartbeat. From that it
 * knows how many accounts each server gained in the last 7 days.
 *
 * Featured servers are grouped by their primary language, because that is how devices filter. In
 * a group with enough newcomers, a server that gained more than `fairness_tolerance_percent` of its
 * fair share (1/n) gets a published `balance` below 1, which devices multiply into the score next
 * to the curators' `weight`. The factor moves halfway towards its target each day and in steps of
 * 5%, so it neither oscillates nor reveals exact numbers.
 */
final class FairnessCalculator
{
    private const WINDOW_DAYS = 7;

    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly DirectorySettings $settings,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Recomputes growth and balance factors (daily, from app:maintenance).
     *
     * @return array<string, mixed> the report (see report())
     */
    public function recompute(): array
    {
        $report = $this->report();
        $changed = [];
        foreach ($this->servers->findInStates(ServerState::Listed, ServerState::Paused) as $server) {
            $row = $report['servers'][$server->domain] ?? null;
            $server->newAccounts7d = $row['new_accounts'] ?? null;
            $target = $row['target_balance'] ?? 100;
            $next = (int) (round((($server->balancePercent + $target) / 2) / 5) * 5);
            if (abs($next - $target) <= 5) {
                $next = $target;
            }
            if ($next !== $server->balancePercent) {
                $changed[$server->domain] = ['from' => $server->balancePercent, 'to' => $next];
                $server->balancePercent = $next;
            }
        }
        if ([] !== $changed) {
            $this->audit->system('fairness.balance_changed', null, ['servers' => $changed]);
        }
        $this->em->flush();

        return $report;
    }

    /**
     * Read-only analysis of the last 7 days.
     *
     * @return array{
     *     window_days: int,
     *     total_new_accounts: int,
     *     featured: int,
     *     hhi: float,
     *     top_share: float,
     *     top3_share: float,
     *     cohorts: array<string, array{servers: int, new_accounts: int, hhi: float, top_share: float, balancing: bool}>,
     *     countries: array<string, array{servers: int, new_accounts: int, share: float}>,
     *     servers: array<string, array{name: string, cohort: string, country: ?string, new_accounts: int, share_in_cohort: float, fair_share: float, target_balance: int, balance: int}>,
     * }
     */
    public function report(): array
    {
        $growth = $this->growth();
        $featured = array_values(array_filter(
            $this->servers->findInStates(ServerState::Listed),
            static fn (Server $s): bool => $s->featured,
        ));

        $cohorts = [];
        foreach ($featured as $server) {
            $cohorts[self::cohort($server)][] = $server;
        }
        ksort($cohorts);

        $tolerance = $this->settings->int('fairness_tolerance_percent') / 100;
        $minBalance = $this->settings->int('fairness_min_balance_percent');
        $minSample = $this->settings->int('fairness_min_new_accounts');

        $rows = [];
        $cohortRows = [];
        foreach ($cohorts as $cohort => $members) {
            $total = 0;
            foreach ($members as $server) {
                $total += $growth[$server->domain] ?? 0;
            }
            $n = \count($members);
            $fair = 1 / $n;
            $balancing = $n >= 2 && $total >= $minSample;
            $shares = [];
            foreach ($members as $server) {
                $new = $growth[$server->domain] ?? 0;
                $share = $total > 0 ? $new / $total : 0.0;
                $shares[] = $share;
                $target = 100;
                if ($balancing && $share > $fair * $tolerance) {
                    $target = max($minBalance, (int) (floor(100 * $fair * $tolerance / $share / 5) * 5));
                }
                $rows[$server->domain] = [
                    'name' => $server->displayName(),
                    'cohort' => $cohort,
                    'country' => self::country($server),
                    'new_accounts' => $new,
                    'share_in_cohort' => round($share, 4),
                    'fair_share' => round($fair, 4),
                    'target_balance' => $target,
                    'balance' => $server->balancePercent,
                ];
            }
            $cohortRows[$cohort] = ['servers' => $n, 'new_accounts' => $total, 'hhi' => self::hhi($shares), 'top_share' => round(max($shares), 4), 'balancing' => $balancing];
        }

        // Overall and per hosting country, across all featured servers.
        $all = 0;
        foreach ($featured as $server) {
            $all += $growth[$server->domain] ?? 0;
        }
        $overall = [];
        $countries = [];
        foreach ($featured as $server) {
            $new = $growth[$server->domain] ?? 0;
            $overall[] = $all > 0 ? $new / $all : 0.0;
            $country = self::country($server) ?? '??';
            $countries[$country] ??= ['servers' => 0, 'new_accounts' => 0, 'share' => 0.0];
            ++$countries[$country]['servers'];
            $countries[$country]['new_accounts'] += $new;
        }
        foreach ($countries as $code => $row) {
            $countries[$code]['share'] = $all > 0 ? round($row['new_accounts'] / $all, 4) : 0.0;
        }
        uasort($countries, static fn (array $a, array $b): int => $b['new_accounts'] <=> $a['new_accounts']);
        rsort($overall);

        return [
            'window_days' => self::WINDOW_DAYS,
            'total_new_accounts' => $all,
            'featured' => \count($featured),
            'hhi' => self::hhi($overall),
            'top_share' => round($overall[0] ?? 0.0, 4),
            'top3_share' => round(array_sum(\array_slice($overall, 0, 3)), 4),
            'cohorts' => $cohortRows,
            'countries' => $countries,
            'servers' => $rows,
        ];
    }

    /**
     * Accounts gained per server domain in the window: the larger of the growth of the account total
     * and the drop in free places among the heartbeats of the window (never negative).
     *
     * @return array<string, int>
     */
    private function growth(): array
    {
        $since = $this->clock->now()->modify('-'.self::WINDOW_DAYS.' days');
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.domain, h.users_total, h.available_accounts FROM heartbeat h JOIN server s ON s.id = h.server_id
             WHERE h.received_at >= ? ORDER BY h.server_id, h.received_at, h.id',
            [$since->format('Y-m-d H:i:s')],
        );
        // Both figures are self-reported. The larger of "accounts gained" and "free places taken"
        // counts, so a server would have to under-report both to escape its balance factor.
        $first = [];
        $last = [];
        $firstFree = [];
        $lastFree = [];
        foreach ($rows as $row) {
            $id = (string) $row['domain'];
            if (null !== $row['users_total']) {
                $first[$id] ??= (int) $row['users_total'];
                $last[$id] = (int) $row['users_total'];
            }
            $firstFree[$id] ??= (int) $row['available_accounts'];
            $lastFree[$id] = (int) $row['available_accounts'];
        }
        $growth = [];
        foreach ($lastFree as $id => $free) {
            $growth[$id] = max(0, isset($last[$id]) ? $last[$id] - $first[$id] : 0, $firstFree[$id] - $free);
        }

        return $growth;
    }

    private static function cohort(Server $server): string
    {
        $languages = $server->profile['audience']['languages'] ?? [];

        return \is_array($languages) && \is_string($languages[0] ?? null) ? $languages[0] : 'und';
    }

    private static function country(Server $server): ?string
    {
        $country = $server->profile['jurisdiction']['hosting_country'] ?? null;

        return \is_string($country) ? $country : null;
    }

    /**
     * Herfindahl–Hirschman index of shares: 1/n when even, 1 when one server takes everyone.
     *
     * @param list<float> $shares
     */
    private static function hhi(array $shares): float
    {
        $sum = 0.0;
        foreach ($shares as $share) {
            $sum += $share * $share;
        }

        return round($sum, 4);
    }
}
