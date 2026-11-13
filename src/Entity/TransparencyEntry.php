<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Public entry for a delisting (concept 12.4, 12.5): domain, date, rule broken, short reason.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transparency_entry')]
#[ORM\Index(name: 'idx_transparency_time', columns: ['decided_at'])]
class TransparencyEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $appealOutcome = null;

    public function __construct(
        #[ORM\Column(length: 253)]
        public string $serverDomain,
        #[ORM\Column(length: 32)]
        public string $kind,
        #[ORM\Column(length: 64)]
        public string $rule,
        #[ORM\Column(type: Types::TEXT)]
        public string $reason,
        #[ORM\Column]
        public \DateTimeImmutable $decidedAt,
    ) {
    }
}
