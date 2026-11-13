<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HeartbeatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One accepted heartbeat. Kept for 90 days, then folded into HeartbeatDaily (concept 11.2).
 */
#[ORM\Entity(repositoryClass: HeartbeatRepository::class)]
#[ORM\Table(name: 'heartbeat')]
#[ORM\Index(name: 'idx_heartbeat_server_time', columns: ['server_id', 'received_at'])]
#[ORM\Index(name: 'idx_heartbeat_time', columns: ['received_at'])]
class Heartbeat
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: Types::BIGINT)]
    public ?string $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        #[ORM\Column]
        public \DateTimeImmutable $receivedAt,
        #[ORM\Column]
        public \DateTimeImmutable $sentAt,
        #[ORM\Column]
        public bool $signupOpen,
        #[ORM\Column]
        public int $availableAccounts,
        #[ORM\Column]
        public int $approvalBacklog,
        #[ORM\Column(nullable: true)]
        public ?int $usersTotal,
        #[ORM\Column(nullable: true)]
        public ?int $usersActiveMonth,
        #[ORM\Column(length: 32)]
        public string $softwareVersion,
        #[ORM\Column]
        public bool $federationOk,
        #[ORM\Column(nullable: true)]
        public ?int $queueLagSeconds,
        #[ORM\Column(nullable: true)]
        public ?float $searchLoad,
    ) {
    }
}
