<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ListBuildRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Every published version of every list (concept 13.2 step 3).
 */
#[ORM\Entity(repositoryClass: ListBuildRepository::class, readOnly: true)]
#[ORM\Table(name: 'list_build')]
#[ORM\UniqueConstraint(name: 'uniq_list_serial', columns: ['list', 'serial'])]
#[ORM\Index(name: 'idx_list_build_time', columns: ['list', 'issued_at'])]
class ListBuild
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /**
     * @param list<string> $keyIds
     */
    public function __construct(
        #[ORM\Column(length: 16)]
        public string $list,
        #[ORM\Column(type: Types::BIGINT)]
        public string $serial,
        #[ORM\Column]
        public \DateTimeImmutable $issuedAt,
        #[ORM\Column]
        public \DateTimeImmutable $expiresAt,
        #[ORM\Column(length: 64)]
        public string $envelopeSha256,
        #[ORM\Column(length: 64)]
        public string $entriesHash,
        #[ORM\Column]
        public int $entryCount,
        #[ORM\Column(type: Types::TEXT)]
        public string $payload,
        #[ORM\Column(type: Types::TEXT)]
        public string $envelope,
        #[ORM\Column(type: Types::JSON)]
        public array $keyIds,
        #[ORM\Column(length: 32)]
        public string $reason,
    ) {
    }
}
