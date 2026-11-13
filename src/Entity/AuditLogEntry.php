<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit log (concept 4.1, 13.4). Rows are never deleted. The only update is sealing:
 * App\Audit\AuditChain gives every entry a sequence number and a hash chained to the previous
 * entry, and publishes a signed head daily, so a rewritten history no longer matches.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class, readOnly: true)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_time', columns: ['created_at'])]
#[ORM\Index(name: 'idx_audit_server', columns: ['server_domain', 'created_at'])]
#[ORM\Index(name: 'idx_audit_action', columns: ['action', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_audit_chain_seq', columns: ['chain_seq'])]
class AuditLogEntry
{
    public const ACTOR_CURATOR = 'curator';
    public const ACTOR_SERVER = 'server';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_PUBLIC = 'public';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)]
    public ?string $id = null;

    /** Position in the hash chain; null until sealed (within about a minute). */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?string $chainSeq = null;

    /** SHA-256 over the previous entry's hash and this entry (App\Audit\AuditChain::entryHash). */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $chainHash = null;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 16)]
        public string $actorType,
        #[ORM\Column(nullable: true)]
        public ?int $actorId,
        #[ORM\Column(length: 254)]
        public string $actorLabel,
        #[ORM\Column(length: 64)]
        public string $action,
        #[ORM\Column(length: 253, nullable: true)]
        public ?string $serverDomain,
        #[ORM\Column(type: Types::JSON)]
        public array $details,
    ) {
    }
}
