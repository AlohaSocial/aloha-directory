<?php

declare(strict_types=1);

namespace App\Entity;

use App\Crypto\JsonCanonicalizer;
use App\Enum\PauseKind;
use App\Enum\ServerState;
use App\Repository\ServerRepository;
use App\Validation\ProfileValidator;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An Aloha server known to the directory. State changes go exclusively through
 * App\Service\ServerLifecycle and App\Curation\CurationService so that every transition is audited.
 */
#[ORM\Entity(repositoryClass: ServerRepository::class)]
#[ORM\Table(name: 'server')]
#[ORM\UniqueConstraint(name: 'uniq_server_domain', columns: ['domain'])]
#[ORM\Index(name: 'idx_server_state', columns: ['state'])]
#[ORM\Index(name: 'idx_server_key', columns: ['key_id'])]
#[ORM\Index(name: 'idx_server_review', columns: ['next_review_at'])]
class Server
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /**
     * Optimistic lock: a curator decision, a heartbeat and the minute job can touch the same server
     * concurrently; a stale write fails instead of silently undoing a pause or a delisting.
     */
    #[ORM\Version, ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    public int $version = 1;

    #[ORM\Column(length: 253)]
    public string $domain;

    /** A server row exists only after its domain was proven (pending enrolments are separate). */
    #[ORM\Column(length: 32, enumType: ServerState::class)]
    public ServerState $state = ServerState::Applied;

    #[ORM\Column(options: ['default' => false])]
    public bool $featured = false;

    /** Accepted by curators as a search provider (concept 10). */
    #[ORM\Column(options: ['default' => false])]
    public bool $searchProvider = false;

    /** Curator weight in percent, 50..200 (concept 9.2: 0.5 to 2). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 100])]
    public int $weightPercent = 100;

    /**
     * Fairness factor (concept 9.2, G3), recomputed daily by App\Fairness\FairnessCalculator:
     * below 100 while the server takes far more than its share of newcomers in its language.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 100])]
    public int $balancePercent = 100;

    /** Accounts created in the last 7 days (from heartbeats); internal, never published. */
    #[ORM\Column(nullable: true)]
    public ?int $newAccounts7d = null;

    // --- Directory key (concept 6.1) -------------------------------------------------------

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $publicKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $keyId = null;

    // --- Admin contact (retained while enrolled plus one year, concept 11.2) ----------------

    #[ORM\Column(length: 254, nullable: true)]
    public ?string $adminEmail = null;

    // --- Lifecycle timestamps ---------------------------------------------------------------

    #[ORM\Column]
    public \DateTimeImmutable $enrolledAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $inReviewAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $listedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $featuredAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $nextReviewAt = null;

    #[ORM\Column(length: 16, nullable: true, enumType: PauseKind::class)]
    public ?PauseKind $pauseKind = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $pausedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $pauseReason = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $delistedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $withdrawnAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $rejectedAt = null;

    /** Public reason of the last delisting or rejection. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $decisionReason = null;

    // --- Listing profile (concept 6.2) ------------------------------------------------------

    /**
     * The profile as currently published: live groups always current, reviewed groups as approved.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $profile = null;

    /**
     * Snapshot taken when curators last approved the profile, for the diff in the console.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $approvedProfile = null;

    /**
     * Submitted profile whose reviewed groups wait for a light re-review.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $pendingProfile = null;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $profileEtag = null;

    /** Set when a heartbeat announced a different profile_etag; the server should PUT its profile. */
    #[ORM\Column(options: ['default' => false])]
    public bool $profileRequested = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $profileUpdatedAt = null;

    // --- Last accepted heartbeat (concept 6.3); exact numbers stay private (concept 13.3) ----

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastHeartbeatAt = null;

    #[ORM\Column(nullable: true)]
    public ?bool $signupOpen = null;

    #[ORM\Column(length: 16, nullable: true)]
    public ?string $signupMode = null;

    #[ORM\Column(nullable: true)]
    public ?int $availableAccounts = null;

    #[ORM\Column(nullable: true)]
    public ?int $approvalBacklog = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $softwareVersion = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $nextcloudVersion = null;

    #[ORM\Column(nullable: true)]
    public ?int $usersTotal = null;

    #[ORM\Column(nullable: true)]
    public ?int $usersActiveMonth = null;

    /** @var list<string>|null search capabilities switched on according to the heartbeat */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $heartbeatSearch = null;

    #[ORM\Column(nullable: true)]
    public ?float $searchLoad = null;

    #[ORM\Column(nullable: true)]
    public ?int $providersUsed = null;

    #[ORM\Column(nullable: true)]
    public ?bool $federationOk = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $federationFailingSince = null;

    #[ORM\Column(nullable: true)]
    public ?int $queueLagSeconds = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $heartbeatAtproto = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $belowMinVersionSince = null;

    /** Abuse contact the last test mail went to (concept 7.2), so it is sent once per address. */
    #[ORM\Column(length: 254, nullable: true)]
    public ?string $abuseTestMailSentTo = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $abuseTestMailSentAt = null;

    /** Result of the Bluesky checks (concept 15.1): a broken promise is never published. */
    #[ORM\Column(nullable: true)]
    public ?bool $atprotoVerified = null;

    // --- Media (concept 8.1: content-addressed, covered by the list signature) --------------

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $logoHash = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $bannerHash = null;

    /** @var array<string, string>|null kind => URL still to fetch */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $mediaPending = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $mediaError = null;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $domain, \DateTimeImmutable $now)
    {
        $this->domain = $domain;
        $this->enrolledAt = $now;
        $this->updatedAt = $now;
    }

    public function weight(): float
    {
        return $this->weightPercent / 100;
    }

    public function isFull(): bool
    {
        return false === $this->signupOpen || (null !== $this->availableAccounts && $this->availableAccounts <= 0);
    }

    /**
     * Reads a dotted path from the published profile, e.g. "moderation.response_hours".
     */
    public function profileValue(string $path): mixed
    {
        $value = $this->profile ?? [];
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function displayName(): string
    {
        $name = $this->profileValue('identity.name');

        return \is_string($name) && '' !== $name ? $name : $this->domain;
    }

    /**
     * SHA-256 of the canonical (RFC 8785) reviewed groups (terms, jurisdiction, operator,
     * moderation) of the current profile. Reviews and listing proposals record it, so a listing can
     * only be confirmed for exactly what the curators checked.
     */
    public function profileFingerprint(): string
    {
        return self::fingerprintReviewed($this->profile ?? []);
    }

    /** The same for the profile changes waiting for review, or null if there are none. */
    public function pendingProfileFingerprint(): ?string
    {
        return null === $this->pendingProfile ? null : self::fingerprintReviewed($this->pendingProfile);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function fingerprintReviewed(array $profile): string
    {
        $reviewed = [];
        foreach (ProfileValidator::REVIEWED_GROUPS as $group) {
            $reviewed[$group] = $profile[$group] ?? null;
        }

        return hash('sha256', JsonCanonicalizer::canonicalize($reviewed));
    }
}
