<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLogEntry;
use App\Entity\Curator;
use App\Entity\Server;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Writes the append-only audit log. Entries are persisted with the caller's unit of work, so an
 * action and its audit entry are committed (or rolled back) together.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function curator(Curator $curator, string $action, ?Server $server = null, array $details = []): void
    {
        $this->write(AuditLogEntry::ACTOR_CURATOR, $curator->id, $curator->email, $action, $server?->domain, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function server(Server $server, string $action, array $details = []): void
    {
        $this->write(AuditLogEntry::ACTOR_SERVER, $server->id, $server->domain, $action, $server->domain, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function system(string $action, ?Server $server = null, array $details = []): void
    {
        $this->write(AuditLogEntry::ACTOR_SYSTEM, null, 'system', $action, $server?->domain, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    public function public(string $action, ?Server $server = null, array $details = [], ?string $domain = null): void
    {
        $this->write(AuditLogEntry::ACTOR_PUBLIC, null, 'public', $action, null !== $server ? $server->domain : $domain, $details);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function write(string $actorType, ?int $actorId, string $label, string $action, ?string $domain, array $details): void
    {
        $this->em->persist(new AuditLogEntry($this->clock->now(), $actorType, $actorId, $label, $action, $domain, $details));
    }
}
