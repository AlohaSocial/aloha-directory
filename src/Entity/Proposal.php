<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProposalAction;
use App\Enum\ProposalStatus;
use App\Repository\ProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProposalRepository::class)]
#[ORM\Table(name: 'proposal')]
#[ORM\Index(name: 'idx_proposal_status', columns: ['status', 'proposed_at'])]
#[ORM\Index(name: 'idx_proposal_server', columns: ['server_id', 'status'])]
class Proposal
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Optimistic lock, so a confirm and a decline racing each other cannot both take effect. */
    #[ORM\Version, ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    public int $version = 1;

    /**
     * For listing an application: Server::profileFingerprint() when it was proposed. Confirming
     * fails if the reviewed profile changed since.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $profileFingerprint = null;

    #[ORM\Column(length: 16, enumType: ProposalStatus::class)]
    public ProposalStatus $status = ProposalStatus::Open;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Curator $decidedBy = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $decisionNote = null;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        #[ORM\Column(length: 32, enumType: ProposalAction::class)]
        public ProposalAction $action,
        #[ORM\Column(type: Types::JSON)]
        public array $params,
        #[ORM\Column(type: Types::TEXT)]
        public string $reason,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public ?Curator $proposedBy,
        #[ORM\Column]
        public \DateTimeImmutable $proposedAt,
    ) {
    }
}
