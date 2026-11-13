<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Heartbeats older than 90 days, aggregated per server and day (concept 11.2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'heartbeat_daily')]
#[ORM\UniqueConstraint(name: 'uniq_heartbeat_daily', columns: ['server_id', 'day'])]
class HeartbeatDaily
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        public \DateTimeImmutable $day,
        #[ORM\Column]
        public int $heartbeats,
        #[ORM\Column]
        public int $minAvailableAccounts,
        #[ORM\Column]
        public int $maxApprovalBacklog,
        #[ORM\Column(nullable: true)]
        public ?int $maxUsersActiveMonth,
        #[ORM\Column]
        public int $federationFailures,
    ) {
    }
}
