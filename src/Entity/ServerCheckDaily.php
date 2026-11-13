<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * How often one automatic check passed and failed on one day; the public check history of a
 * server page (concept 13.5). Kept for a year.
 */
#[ORM\Entity]
#[ORM\Table(name: 'server_check_daily')]
#[ORM\UniqueConstraint(name: 'uniq_check_daily', columns: ['server_id', 'day', 'name'])]
#[ORM\Index(name: 'idx_check_daily_day', columns: ['day'])]
class ServerCheckDaily
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $passes = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $failures = 0;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        #[ORM\Column(length: 10)]
        public string $day,
        #[ORM\Column(length: 32)]
        public string $name,
    ) {
    }
}
