<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'curator_invite')]
#[ORM\UniqueConstraint(name: 'uniq_invite_token', columns: ['token_hash'])]
class CuratorInvite
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\Column(length: 180)]
        public string $email,
        #[ORM\Column(length: 100)]
        public string $displayName,
        #[ORM\Column]
        public bool $admin,
        #[ORM\Column(length: 64)]
        public string $tokenHash,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public ?Curator $invitedBy,
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
        #[ORM\Column]
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
