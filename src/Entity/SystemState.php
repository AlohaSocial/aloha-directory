<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Small key/value store for operational state and counters (last tick, client signature failures).
 */
#[ORM\Entity]
#[ORM\Table(name: 'system_state')]
class SystemState
{
    #[ORM\Column(type: Types::TEXT)]
    public string $value = '';

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id, ORM\Column(length: 64)]
        public string $name,
        \DateTimeImmutable $now,
    ) {
        $this->updatedAt = $now;
    }
}
