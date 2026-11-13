<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Latest result of one automatic check for one server (concept 7.2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'server_check')]
#[ORM\UniqueConstraint(name: 'uniq_server_check', columns: ['server_id', 'name'])]
class ServerCheck
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public bool $passed = false;

    #[ORM\Column(type: Types::TEXT)]
    public string $message = '';

    #[ORM\Column]
    public \DateTimeImmutable $checkedAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $failingSince = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $consecutiveFailures = 0;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Server $server,
        #[ORM\Column(length: 32)]
        public string $name,
        \DateTimeImmutable $now,
    ) {
        $this->checkedAt = $now;
    }

    public function record(bool $passed, string $message, \DateTimeImmutable $now): void
    {
        $this->passed = $passed;
        $this->message = mb_substr($message, 0, 2000);
        $this->checkedAt = $now;
        if ($passed) {
            $this->failingSince = null;
            $this->consecutiveFailures = 0;
        } else {
            $this->failingSince ??= $now;
            ++$this->consecutiveFailures;
        }
    }
}
