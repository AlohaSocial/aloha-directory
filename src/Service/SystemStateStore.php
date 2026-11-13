<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SystemState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class SystemStateStore
{
    public const LAST_TICK = 'last_tick';
    public const LAST_CHECK_RUN = 'last_check_run';
    public const LAST_MAINTENANCE = 'last_maintenance';
    public const AUDIT_VERIFICATION = 'audit_verification';
    public const FAIRNESS = 'fairness';
    public const COUNTER_PREFIX = 'counter.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    public function get(string $name): ?SystemState
    {
        return $this->em->find(SystemState::class, $name);
    }

    public function set(string $name, string $value): void
    {
        $state = $this->get($name) ?? new SystemState($name, $this->clock->now());
        $state->value = $value;
        $state->updatedAt = $this->clock->now();
        $this->em->persist($state);
    }

    /**
     * Atomic increment that does not go through the unit of work (safe under concurrency).
     */
    public function increment(string $counter): void
    {
        $name = self::COUNTER_PREFIX.$counter;
        $now = $this->clock->now();
        $updated = $this->connection->executeStatement(
            "UPDATE system_state SET value = CAST(CAST(value AS DECIMAL(20,0)) + 1 AS CHAR), updated_at = ? WHERE name = ?",
            [$now, $name],
            [Types::DATETIME_IMMUTABLE, Types::STRING],
        );
        if (0 === $updated) {
            try {
                $this->connection->insert('system_state', ['name' => $name, 'value' => '1', 'updated_at' => $now], ['updated_at' => Types::DATETIME_IMMUTABLE]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                $this->increment($counter);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    public function counters(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT name, value FROM system_state WHERE name LIKE ?', [self::COUNTER_PREFIX.'%']);
        $out = [];
        foreach ($rows as $row) {
            $out[substr((string) $row['name'], \strlen(self::COUNTER_PREFIX))] = (int) $row['value'];
        }

        return $out;
    }
}
