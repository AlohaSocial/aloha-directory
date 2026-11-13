<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The directory's own block list plus imported fediverse block lists (concept 7.2).
 * A block on example.org also covers every subdomain.
 */
#[ORM\Entity]
#[ORM\Table(name: 'blocked_domain')]
#[ORM\UniqueConstraint(name: 'uniq_blocked_domain', columns: ['domain', 'source'])]
#[ORM\Index(name: 'idx_blocked_domain', columns: ['domain'])]
class BlockedDomain
{
    public const SOURCE_DIRECTORY = 'directory';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 253)]
        public string $domain,
        #[ORM\Column(length: 64)]
        public string $source,
        #[ORM\Column(length: 500)]
        public string $reason,
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
