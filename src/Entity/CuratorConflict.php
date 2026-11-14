<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A declared conflict of interest: the curator never reviews or decides anything about this server.
 */
#[ORM\Entity]
#[ORM\Table(name: 'curator_conflict')]
#[ORM\UniqueConstraint(name: 'uniq_conflict', columns: ['curator_id', 'server_id'])]
class CuratorConflict
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Curator $curator,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        #[ORM\Column(length: 500)]
        public string $note,
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
