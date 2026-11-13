<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An enrolment waiting for its domain proof (concept 6.1). Every enrol request gets its own record
 * and challenge, so an anonymous request can never evict or replace someone else's pending
 * enrolment. The contact address of the record whose challenge the domain publishes becomes the
 * server's contact once the domain proof succeeded.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pending_enrolment')]
#[ORM\Index(name: 'idx_pending_domain_key', columns: ['domain', 'key_id'])]
#[ORM\Index(name: 'idx_pending_expiry', columns: ['expires_at'])]
class PendingEnrolment
{
    public const VERIFY_QUEUED = 'queued';
    public const VERIFY_FAILED = 'failed';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** null (not requested), queued or failed; on success the record is gone. */
    #[ORM\Column(length: 16, nullable: true)]
    public ?string $verifyStatus = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $verifyRequestedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $verifyFinishedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $verifyError = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $verifyMessage = null;

    public function __construct(
        #[ORM\Column(length: 253)]
        public string $domain,
        #[ORM\Column(length: 64)]
        public string $publicKey,
        #[ORM\Column(length: 64)]
        public string $keyId,
        #[ORM\Column(length: 64)]
        public string $challengeHash,
        #[ORM\Column(length: 254, nullable: true)]
        public ?string $contactEmail,
        #[ORM\Column]
        public \DateTimeImmutable $createdAt,
        #[ORM\Column]
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
