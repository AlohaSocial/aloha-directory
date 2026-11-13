<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommentVisibility;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A curator note, a question to the server admin, or an admin's answer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comment')]
#[ORM\Index(name: 'idx_comment_server', columns: ['server_id', 'created_at'])]
class Comment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        /** null when the server admin wrote it */
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public ?Curator $curator,
        #[ORM\Column]
        public bool $fromServer,
        #[ORM\Column(length: 16, enumType: CommentVisibility::class)]
        public CommentVisibility $visibility,
        #[ORM\Column(type: Types::TEXT)]
        public string $body,
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public ?Complaint $complaint = null,
    ) {
    }
}
