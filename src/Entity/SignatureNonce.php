<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Hashes of accepted request signatures, kept for the replay window only.
 */
#[ORM\Entity]
#[ORM\Table(name: 'signature_nonce')]
#[ORM\Index(name: 'idx_nonce_expiry', columns: ['expires_at'])]
class SignatureNonce
{
    public function __construct(
        #[ORM\Id, ORM\Column(length: 64)]
        public string $hash,
        #[ORM\Column]
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
