<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A WebAuthn credential (passkey or security key) of a curator, the console's second factor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'curator_passkey')]
#[ORM\UniqueConstraint(name: 'uniq_passkey_credential', columns: ['credential_id'])]
class CuratorPasskey
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(
        #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Curator $curator,
        /** base64url of the raw credential id */
        #[ORM\Column(length: 255)]
        public string $credentialId,
        /** The credential record (public key, counter, flags) as the WebAuthn library serialises it. */
        #[ORM\Column(type: Types::TEXT)]
        public string $record,
        #[ORM\Column(length: 64)]
        public string $name,
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
