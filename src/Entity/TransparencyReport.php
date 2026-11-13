<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'transparency_report')]
#[ORM\UniqueConstraint(name: 'uniq_report_quarter', columns: ['quarter'])]
class TransparencyReport
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        #[ORM\Column(length: 7)]
        public string $quarter,
        #[ORM\Column]
        public \DateTimeImmutable $generatedAt,
        #[ORM\Column(type: Types::JSON)]
        public array $data,
    ) {
    }
}
