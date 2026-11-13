<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A published head of the audit hash chain (one per day). The signed envelope is also on the
 * public website and in the Git mirror, so the history it commits to can be checked by anyone
 * who kept a copy.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'audit_head')]
class AuditHead
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 10)]
        public string $day,
        #[ORM\Column(type: Types::BIGINT)]
        public string $seq,
        #[ORM\Column(length: 64)]
        public string $hash,
        #[ORM\Column]
        public \DateTimeImmutable $issuedAt,
        #[ORM\Column(type: Types::TEXT)]
        public string $envelope,
    ) {
    }
}
