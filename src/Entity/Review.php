<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A completed review checklist (concept 7.3 for the initial review, 7.5 for re-reviews).
 */
#[ORM\Entity]
#[ORM\Table(name: 'review')]
#[ORM\Index(name: 'idx_review_server', columns: ['server_id', 'created_at'])]
class Review
{
    public const KIND_INITIAL = 'initial';
    public const KIND_REREVIEW = 'rereview';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Server::profileFingerprint() of the profile this checklist was completed for. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $profileFingerprint = null;

    /**
     * @param array<string, bool> $checklist
     */
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public ?Curator $curator,
        #[ORM\Column(length: 16)]
        public string $kind,
        #[ORM\Column(type: Types::JSON)]
        public array $checklist,
        #[ORM\Column(type: Types::TEXT)]
        public string $notes,
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
