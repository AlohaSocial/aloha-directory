<?php

declare(strict_types=1);

namespace App\Site;

use App\Check\Checks;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The reliability section of the public server pages (concept 13.5): uptime from heartbeats and
 * the history of the automatic checks. Only pass/fail per day is published, never check messages
 * or heartbeat numbers.
 */
final class ServerHistory
{
    public const UPTIME_DAYS = 90;
    public const CHECK_DAYS = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $entries list entries (domain, listed_since)
     *
     * @return array<string, array{
     *     uptime: list<array{day: string, percent: float|null}>,
     *     uptime_30: float|null,
     *     uptime_90: float|null,
     *     checks: list<array{name: string, label: string, current: bool|null, days: list<array{day: string, state: string}>}>,
     * }>
     */
    public function forEntries(array $entries): array
    {
        $domains = array_values(array_filter(array_map(static fn (array $e): mixed => $e['domain'] ?? null, $entries), 'is_string'));
        if ([] === $domains) {
            return [];
        }
        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        $uptimeDays = self::days($now, self::UPTIME_DAYS);
        $checkDays = self::days($now, self::CHECK_DAYS);

        $hours = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT s.domain, SUBSTR(h.received_at, 1, 10) AS day, COUNT(DISTINCT SUBSTR(h.received_at, 1, 13)) AS hours
             FROM heartbeat h JOIN server s ON s.id = h.server_id
             WHERE s.domain IN (?) AND h.received_at >= ? GROUP BY s.domain, SUBSTR(h.received_at, 1, 10)',
            [$domains, $uptimeDays[0].' 00:00:00'],
            [ArrayParameterType::STRING],
        ) as $row) {
            $hours[$row['domain']][$row['day']] = (int) $row['hours'];
        }

        $checks = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT s.domain, d.day, d.name, d.passes, d.failures FROM server_check_daily d JOIN server s ON s.id = d.server_id
             WHERE s.domain IN (?) AND d.day >= ?',
            [$domains, $checkDays[0]],
            [ArrayParameterType::STRING],
        ) as $row) {
            $checks[$row['domain']][$row['name']][$row['day']] = [(int) $row['passes'], (int) $row['failures']];
        }
        $current = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT s.domain, c.name, c.passed FROM server_check c JOIN server s ON s.id = c.server_id WHERE s.domain IN (?)',
            [$domains],
            [ArrayParameterType::STRING],
        ) as $row) {
            $current[$row['domain']][$row['name']] = (bool) $row['passed'];
        }

        $result = [];
        foreach ($entries as $entry) {
            $domain = $entry['domain'];
            $listedSince = \is_string($entry['listed_since'] ?? null) ? $entry['listed_since'] : $uptimeDays[0];

            $uptime = [];
            foreach ($uptimeDays as $day) {
                if ($day < $listedSince) {
                    $uptime[] = ['day' => $day, 'percent' => null];
                    continue;
                }
                $possible = $day === $today ? max(1, (int) $now->format('G') + 1) : 24;
                $uptime[] = ['day' => $day, 'percent' => round(100 * min($possible, $hours[$domain][$day] ?? 0) / $possible, 1)];
            }

            $rows = [];
            foreach (Checks::LABELS as $name => $label) {
                if (!isset($current[$domain][$name]) && !isset($checks[$domain][$name])) {
                    continue;
                }
                $days = [];
                foreach ($checkDays as $day) {
                    [$passes, $failures] = $checks[$domain][$name][$day] ?? [0, 0];
                    $days[] = ['day' => $day, 'state' => match (true) {
                        0 === $passes + $failures => 'none',
                        0 === $failures => 'pass',
                        0 === $passes => 'fail',
                        default => 'mixed',
                    }];
                }
                $rows[] = ['name' => $name, 'label' => $label, 'current' => $current[$domain][$name] ?? null, 'days' => $days];
            }

            $result[$domain] = [
                'uptime' => $uptime,
                'uptime_30' => self::average(\array_slice($uptime, -30)),
                'uptime_90' => self::average($uptime),
                'checks' => $rows,
            ];
        }

        return $result;
    }

    /**
     * @return list<string> the last $n days, oldest first, ending today
     */
    private static function days(\DateTimeImmutable $now, int $n): array
    {
        $days = [];
        for ($i = $n - 1; $i >= 0; --$i) {
            $days[] = $now->modify("-{$i} days")->format('Y-m-d');
        }

        return $days;
    }

    /**
     * @param list<array{day: string, percent: float|null}> $uptime
     */
    private static function average(array $uptime): ?float
    {
        $values = array_values(array_filter(array_column($uptime, 'percent'), static fn (mixed $v): bool => null !== $v));

        return [] === $values ? null : round(array_sum($values) / \count($values), 1);
    }
}
