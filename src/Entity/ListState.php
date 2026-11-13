<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'list_state')]
class ListState
{
    #[ORM\Column(type: Types::BIGINT)]
    public string $serial = '0';

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastBuiltAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $entriesHash = null;

    /** First time the entries were seen to differ from the last build (for the 10-minute alarm). */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $changePendingSince = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $forceRebuild = false;

    /** Set while a build is held by the change guard (ListBuilder::guard). */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $heldSince = null;

    /** Entries hash of the held content. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $heldHash = null;

    /**
     * @var array{added: list<string>, removed: list<string>, before: int, after: int}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $heldSummary = null;

    /** Entries hash a curator released; content with exactly this hash is published despite the guard. */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $releasedHash = null;

    public function __construct(
        #[ORM\Id, ORM\Column(length: 16)]
        public string $name,
    ) {
    }
}
