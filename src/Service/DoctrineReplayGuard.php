<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Signature\ReplayGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

/**
 * Replay protection backed by a primary key: concurrent requests with the same signature can never
 * both succeed, also across several application hosts.
 */
final class DoctrineReplayGuard implements ReplayGuard
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function firstSeen(string $fingerprint, \DateTimeImmutable $expiresAt): bool
    {
        try {
            $this->connection->insert('signature_nonce', ['hash' => $fingerprint, 'expires_at' => $expiresAt], ['expires_at' => Types::DATETIME_IMMUTABLE]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM signature_nonce WHERE expires_at < ?', [$now], [Types::DATETIME_IMMUTABLE]);
    }
}
