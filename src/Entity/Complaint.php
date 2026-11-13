<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ComplaintStatus;
use App\Repository\ComplaintRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A report about a listed server (concept 7.5). The reporter's contact is private: the server
 * admin only ever sees category and text.
 */
#[ORM\Entity(repositoryClass: ComplaintRepository::class)]
#[ORM\Table(name: 'complaint')]
#[ORM\Index(name: 'idx_complaint_status', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_complaint_server', columns: ['server_id', 'status'])]
class Complaint
{
    public const CATEGORIES = [
        'illegal_content' => 'Illegal content left up',
        'moderation' => 'Moderation does not work as promised',
        'privacy' => 'Privacy or data protection',
        'security' => 'Security incident or data breach',
        'misleading_profile' => 'The listing is not accurate',
        'unreachable' => 'The operator is unreachable or the server is gone',
        'other' => 'Something else',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 16, enumType: ComplaintStatus::class)]
    public ComplaintStatus $status = ComplaintStatus::Open;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $sharedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Curator $closedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $resolution = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        #[ORM\Column(length: 32)]
        public string $category,
        #[ORM\Column(type: Types::TEXT)]
        public string $body,
        #[ORM\Column(length: 254, nullable: true)]
        public ?string $reporterEmail,
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
