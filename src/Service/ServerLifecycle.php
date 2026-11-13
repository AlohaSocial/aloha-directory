<?php

declare(strict_types=1);

namespace App\Service;

use App\Check\Checks;
use App\Check\NodeinfoReader;
use App\Crypto\Base64Url;
use App\Crypto\Ed25519;
use App\Entity\Comment;
use App\Entity\Heartbeat;
use App\Entity\PendingEnrolment;
use App\Entity\Proposal;
use App\Entity\Server;
use App\Entity\ServerCheck;
use App\Entity\TransparencyEntry;
use App\Enum\CommentVisibility;
use App\Enum\PauseKind;
use App\Enum\ProposalAction;
use App\Enum\ProposalStatus;
use App\Enum\ServerState;
use App\Net\DomainName;
use App\Net\FetchException;
use App\Net\SafeHttpClient;
use App\Repository\ProposalRepository;
use App\Repository\ServerRepository;
use App\Validation\ProfileValidator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Everything a server does to itself (enrol, verify, profile, heartbeat, withdraw, key rotation)
 * and every automatic state transition (concept 6.4, 7.1). Curator decisions live in
 * App\Curation\CurationService and use the transition helpers at the bottom of this class.
 */
final class ServerLifecycle
{
    private const CHALLENGE_TTL = 'PT1H';
    private const MAX_PENDING_TOTAL = 20_000;
    private const VERIFY_TIME_BUDGET_SECONDS = 12.0;
    private const HEARTBEAT_CLOCK_SKEW_SECONDS = 600;
    private const KEY_ROTATION_CONTEXT = 'aloha-directory-key-rotation:';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServerRepository $servers,
        private readonly ProposalRepository $proposals,
        private readonly SafeHttpClient $http,
        private readonly NodeinfoReader $nodeinfo,
        private readonly Blocklist $blocklist,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    // ---------------------------------------------------------------------------------------
    // Enrolment and proof of control (concept 6.1)
    // ---------------------------------------------------------------------------------------

    /**
     * Anonymous: records a pending enrolment for one key. It changes nothing about an existing
     * server until verify() succeeded with that key.
     *
     * @return array{pending: PendingEnrolment, challenge: string}
     */
    public function enrol(string $domainInput, string $publicKeyInput, ?string $adminEmail): array
    {
        try {
            $domain = DomainName::normalize($domainInput);
        } catch (\InvalidArgumentException $e) {
            throw new ApiProblem(422, 'invalid_domain', $e->getMessage());
        }
        try {
            $publicKey = Ed25519::decodePublicKey($publicKeyInput);
        } catch (\InvalidArgumentException) {
            throw new ApiProblem(422, 'invalid_public_key', 'public_key must be a base64url-encoded 32-byte Ed25519 public key.');
        }
        if (null !== $adminEmail && (\strlen($adminEmail) > 254 || false === filter_var($adminEmail, \FILTER_VALIDATE_EMAIL))) {
            throw new ApiProblem(422, 'invalid_contact', 'contact_email must be an e-mail address.');
        }
        if (null !== ($block = $this->blocklist->match($domain))) {
            throw new ApiProblem(403, 'domain_blocked', 'This domain cannot be listed in the directory.', ['source' => $block->source]);
        }

        $now = $this->clock->now();
        $server = $this->servers->findOneByDomain($domain);
        if (null !== $server && ServerState::Delisted === $server->state && null !== $server->delistedAt
            && $server->delistedAt->modify('+'.$this->settings->int('reapply_after_delisting_months').' months') > $now) {
            throw new ApiProblem(403, 'reapply_too_early', 'A delisted server may apply again six months after the decision.', [
                'not_before' => $server->delistedAt->modify('+'.$this->settings->int('reapply_after_delisting_months').' months')->format(\DATE_ATOM),
            ]);
        }

        // Every enrol gets its own record and challenge, even for the same domain and key. There is
        // deliberately no per-domain cap: anyone can enrol any domain, so a cap (or replacing an
        // existing challenge) would let a stranger lock the real admin out. verify() later uses the
        // record whose challenge the domain itself publishes. Storage is bounded by the per-IP limit
        // and this global ceiling.
        $keyId = Ed25519::fingerprint($publicKey);
        $open = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')->from(PendingEnrolment::class, 'p')
            ->where('p.expiresAt >= :now')->setParameter('now', $now)
            ->getQuery()->getSingleScalarResult();
        if ($open >= self::MAX_PENDING_TOTAL) {
            throw new ApiProblem(503, 'enrolment_unavailable', 'Enrolment is temporarily unavailable; try again later.', [], ['Retry-After' => '3600']);
        }

        $challenge = Base64Url::encode(random_bytes(32));
        $pending = new PendingEnrolment(
            $domain, Base64Url::encode($publicKey), $keyId, hash('sha256', $challenge),
            null === $adminEmail ? null : mb_strtolower($adminEmail), $now, $now->add(new \DateInterval(self::CHALLENGE_TTL)),
        );
        $this->em->persist($pending);
        $this->audit->public('server.enrol_requested', $server, ['key_id' => $keyId], $domain);

        return ['pending' => $pending, 'challenge' => $challenge];
    }

    /**
     * Called with a request signed by the pending key. Fetches the challenge from the domain and
     * checks nodeinfo; on success the pending key becomes the server's key (and its contact the
     * server's contact). All other pending enrolments of the domain are discarded.
     */
    public function verify(string $domain, string $keyId): Server
    {
        $now = $this->clock->now();
        $candidates = array_values(array_filter(
            $this->em->getRepository(PendingEnrolment::class)->findBy(['domain' => $domain, 'keyId' => $keyId]),
            static fn (PendingEnrolment $p): bool => $p->expiresAt >= $now,
        ));
        if ([] === $candidates) {
            throw new ApiProblem(410, 'challenge_expired', 'The challenge has expired; enrol again.');
        }

        // One time budget for all outbound requests of this call (finding: verify must not tie up workers).
        $deadline = microtime(true) + self::VERIFY_TIME_BUDGET_SECONDS;
        try {
            $result = $this->http->get('https://'.$domain.'/.well-known/aloha-directory', 4096, ['Accept' => 'application/json'], deadline: $deadline);
            if (200 !== $result->status) {
                throw new FetchException(\sprintf('/.well-known/aloha-directory answered HTTP %d.', $result->status));
            }
            $doc = $result->json(4);
        } catch (FetchException $e) {
            throw new ApiProblem(422, 'challenge_unreachable', $e->getMessage());
        }
        $challenge = $doc['challenge'] ?? null;
        $pending = null;
        if (\is_string($challenge)) {
            $hash = hash('sha256', $challenge);
            foreach ($candidates as $candidate) {
                if (hash_equals($candidate->challengeHash, $hash)) {
                    $pending = $candidate;
                }
            }
        }
        if (null === $pending) {
            throw new ApiProblem(422, 'challenge_mismatch', '/.well-known/aloha-directory does not contain the expected challenge.');
        }
        if (($doc['key_id'] ?? null) !== $pending->keyId) {
            throw new ApiProblem(422, 'challenge_mismatch', '/.well-known/aloha-directory names a different key_id.');
        }

        try {
            $info = $this->nodeinfo->read($domain, $deadline);
        } catch (FetchException $e) {
            throw new ApiProblem(422, 'nodeinfo_unavailable', $e->getMessage());
        }
        if (!\in_array($info->softwareName, $this->settings->softwareNames, true)) {
            throw new ApiProblem(422, 'not_aloha', 'Only Aloha servers can be listed (nodeinfo software.name).');
        }
        if (!VersionComparator::atLeast($info->version, $this->settings->minServerVersion)) {
            throw new ApiProblem(422, 'version_too_old', \sprintf('Aloha %s or newer is required.', $this->settings->minServerVersion));
        }

        $server = $this->servers->findOneByDomain($domain);
        if (null === $server) {
            $server = new Server($domain, $now);
            $this->em->persist($server);
        }
        $rotation = null !== $server->verifiedAt && $server->state->isActive();
        $previousContact = $server->adminEmail;

        $server->publicKey = $pending->publicKey;
        $server->keyId = $pending->keyId;
        $server->verifiedAt = $now;
        $server->updatedAt = $now;
        if (null !== $pending->contactEmail) {
            $server->adminEmail = $pending->contactEmail; // the domain proof makes this contact authoritative
        }
        foreach ($this->em->getRepository(PendingEnrolment::class)->findBy(['domain' => $domain]) as $other) {
            $this->em->remove($other);
        }

        if ($rotation) {
            $this->audit->server($server, 'server.key_rotated', ['key_id' => $server->keyId, 'method' => 'challenge']);
            if (null !== $previousContact) {
                $this->notifier->send($previousContact, 'The directory key of '.$domain.' was replaced',
                    "Someone who controls {$domain} replaced the server's Aloha Directory key by proving control of the domain.\n\nIf this was not you, your server or domain may be compromised: please contact the curators.");
            }

            return $server;
        }

        $this->resetForApplication($server, $now);
        $this->audit->server($server, 'server.verified', ['key_id' => $server->keyId, 'software_version' => $info->version]);

        return $server;
    }


    /**
     * Key rotation signed with the current key (concept 6.1). The proof shows the server holds the new key.
     */
    public function rotateKey(Server $server, string $newKeyInput, string $proofInput): void
    {
        try {
            $newKey = Ed25519::decodePublicKey($newKeyInput);
            $proof = Base64Url::decode($proofInput);
        } catch (\InvalidArgumentException) {
            throw new ApiProblem(422, 'invalid_public_key', 'public_key and proof must be base64url.');
        }
        $fingerprint = Ed25519::fingerprint($newKey);
        if (!Ed25519::verify($proof, self::KEY_ROTATION_CONTEXT.$server->domain.':'.$fingerprint, $newKey)) {
            throw new ApiProblem(422, 'invalid_proof', 'proof must be the new key\'s signature over "'.self::KEY_ROTATION_CONTEXT.'<domain>:<new key id>".');
        }
        $server->publicKey = Base64Url::encode($newKey);
        $server->keyId = $fingerprint;
        $server->updatedAt = $this->clock->now();
        $this->audit->server($server, 'server.key_rotated', ['key_id' => $fingerprint, 'method' => 'signed']);
    }

    // ---------------------------------------------------------------------------------------
    // Profile (concept 6.2)
    // ---------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $profile validated by ProfileValidator
     *
     * @return array{pending_review: bool, changed_groups: list<string>}
     */
    public function acceptProfile(Server $server, array $profile, ?string $contactEmail): array
    {
        $this->assertActive($server);
        $now = $this->clock->now();
        $previous = $server->profile ?? [];

        $changed = [];
        foreach ([...ProfileValidator::REVIEWED_GROUPS, ...ProfileValidator::LIVE_GROUPS] as $group) {
            if (($previous[$group] ?? null) !== ($profile[$group] ?? null)) {
                $changed[] = $group;
            }
        }
        $reviewedChanged = array_values(array_intersect($changed, ProfileValidator::REVIEWED_GROUPS));

        $approved = null !== $server->approvedProfile && \in_array($server->state, [ServerState::Listed, ServerState::Paused], true);
        $pendingReview = false;
        if ($approved) {
            // Live groups go live at once; reviewed groups keep their approved values until a curator accepts them.
            $published = $profile;
            foreach (ProfileValidator::REVIEWED_GROUPS as $group) {
                $published[$group] = $server->approvedProfile[$group] ?? $previous[$group] ?? null;
            }
            $differsFromApproved = false;
            foreach (ProfileValidator::REVIEWED_GROUPS as $group) {
                if (($profile[$group] ?? null) !== ($server->approvedProfile[$group] ?? null)) {
                    $differsFromApproved = true;
                }
            }
            $server->profile = $published;
            $server->pendingProfile = $differsFromApproved ? $profile : null;
            $pendingReview = $differsFromApproved;
        } else {
            $server->profile = $profile;
            $server->pendingProfile = null;
        }

        foreach (['logo' => 'logo_url', 'banner' => 'banner_url'] as $kind => $field) {
            $newUrl = $profile['identity'][$field] ?? null;
            if ($newUrl !== ($previous['identity'][$field] ?? null)) {
                $pending = $server->mediaPending ?? [];
                if (null === $newUrl) {
                    unset($pending[$kind]);
                    'logo' === $kind ? $server->logoHash = null : $server->bannerHash = null;
                } else {
                    $pending[$kind] = $newUrl;
                }
                $server->mediaPending = [] === $pending ? null : $pending;
            }
        }

        if (null !== $contactEmail) {
            if (\strlen($contactEmail) > 254 || false === filter_var($contactEmail, \FILTER_VALIDATE_EMAIL)) {
                throw new ApiProblem(422, 'invalid_contact', 'contact_email must be an e-mail address.');
            }
            $server->adminEmail = mb_strtolower($contactEmail);
        }

        $server->profileEtag = $profile['etag'] ?? null;
        $server->profileRequested = false;
        $server->profileUpdatedAt = $now;
        $server->updatedAt = $now;

        if ([] !== $changed) {
            $this->audit->server($server, 'server.profile_updated', ['groups' => $changed, 'pending_review' => $pendingReview]);
        }

        return ['pending_review' => $pendingReview, 'changed_groups' => $reviewedChanged];
    }

    // ---------------------------------------------------------------------------------------
    // Heartbeat (concept 6.3, 6.4)
    // ---------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $hb validated by HeartbeatValidator
     */
    public function acceptHeartbeat(Server $server, array $hb): void
    {
        $this->assertActive($server);
        $now = $this->clock->now();

        if ($hb['domain'] !== $server->domain) {
            throw new ApiProblem(422, 'domain_mismatch', 'The heartbeat names a different domain.');
        }
        /** @var \DateTimeImmutable $sentAt */
        $sentAt = $hb['sent_at'];
        if (abs($now->getTimestamp() - $sentAt->getTimestamp()) > self::HEARTBEAT_CLOCK_SKEW_SECONDS) {
            throw new ApiProblem(422, 'clock_skew', 'sent_at is more than 10 minutes off; check the server clock.');
        }
        $minInterval = $this->settings->int('heartbeat_min_interval_seconds');
        if (null !== $server->lastHeartbeatAt && $now->getTimestamp() - $server->lastHeartbeatAt->getTimestamp() < $minInterval) {
            $retry = $minInterval - ($now->getTimestamp() - $server->lastHeartbeatAt->getTimestamp());
            throw new ApiProblem(429, 'too_frequent', 'Heartbeats are expected once an hour.', [], ['Retry-After' => (string) max(1, $retry)]);
        }
        if (!\in_array(strtolower((string) $hb['software']['name']), $this->settings->softwareNames, true)) {
            throw new ApiProblem(422, 'not_aloha', 'software.name is not an Aloha identifier.');
        }

        $signup = $hb['signup'];
        $search = $hb['search'];
        $health = $hb['health'];

        $server->lastHeartbeatAt = $now;
        $server->signupOpen = $signup['open'] && \in_array($signup['mode'], ['open', 'approval'], true);
        $server->signupMode = $signup['mode'];
        $server->availableAccounts = $signup['available_accounts'];
        $server->approvalBacklog = $signup['approval_backlog'];
        $server->softwareVersion = $hb['software']['version'];
        $server->nextcloudVersion = $hb['software']['nextcloud'];
        $server->usersTotal = $hb['usage']['users_total'];
        $server->usersActiveMonth = $hb['usage']['users_active_month'];
        $server->heartbeatSearch = array_values(array_filter(
            ProfileValidator::SEARCH_CAPABILITIES,
            static fn (string $c): bool => true === ($search[$c] ?? false),
        ));
        $server->searchLoad = $search['load'];
        $server->providersUsed = $search['providers_used'];
        $server->queueLagSeconds = $health['queue_lag_seconds'];
        $server->federationOk = $health['federation_ok'];
        $server->federationFailingSince = $health['federation_ok'] ? null : ($server->federationFailingSince ?? $now);
        $server->heartbeatAtproto = $hb['atproto'] ?? null;

        if (VersionComparator::atLeast((string) $server->softwareVersion, $this->settings->minServerVersion)) {
            $server->belowMinVersionSince = null;
        } else {
            $server->belowMinVersionSince ??= $now;
        }

        if (null !== $hb['profile_etag'] && $hb['profile_etag'] !== $server->profileEtag) {
            $server->profileRequested = true;
        } elseif (null === $server->profile) {
            $server->profileRequested = true;
        }
        $server->updatedAt = $now;

        $this->em->persist(new Heartbeat(
            $server, $now, $sentAt, (bool) $server->signupOpen, $signup['available_accounts'], $signup['approval_backlog'],
            $server->usersTotal, $server->usersActiveMonth, (string) $server->softwareVersion, $health['federation_ok'],
            $server->queueLagSeconds, $server->searchLoad,
        ));

        $this->evaluate($server);
    }

    public function withdraw(Server $server): void
    {
        if (!$server->state->isActive()) {
            throw new ApiProblem(409, 'not_active', 'The server is not enrolled.');
        }
        $now = $this->clock->now();

        // Withdrawing cannot be used to escape a delisting: an open delisting proposal is then
        // recorded publicly and the re-application wait applies, as for a delisting.
        foreach ($this->proposals->findOpen($server) as $proposal) {
            if (ProposalAction::Delist === $proposal->action) {
                $reason = (string) ($proposal->params['public_reason'] ?? 'A delisting was pending.').' (The server withdrew while the delisting was pending.)';
                $this->markDelisted($server, $reason);
                $this->em->persist(new TransparencyEntry($server->domain, 'withdrawn_during_delisting', (string) ($proposal->params['rule'] ?? 'listing_criteria'), $reason, $now));
                $this->audit->server($server, 'server.withdrawn_during_delisting', ['proposal' => $proposal->id]);

                return;
            }
        }

        $server->state = ServerState::Withdrawn;
        $server->withdrawnAt = $now;
        $server->featured = false;
        $server->searchProvider = false;
        $server->pauseKind = null;
        $server->updatedAt = $now;
        $this->closeOpenProposals($server, 'The server withdrew from the directory.');
        $this->audit->server($server, 'server.withdrawn');
    }

    // ---------------------------------------------------------------------------------------
    // Automatic transitions (concept 6.4, 13.6)
    // ---------------------------------------------------------------------------------------

    /**
     * Applies the automatic pause and resume rules to one server. Idempotent; called after every
     * heartbeat, by the checker, and every minute by the tick for time-based rules.
     */
    /**
     * Runs $change on a freshly read, row-locked server and commits it. Background jobs use this
     * for their writes after slow work (outbound requests), so they decide on current state and
     * never collide with a curator decision or a heartbeat (Server is also optimistically locked).
     *
     * @template T
     *
     * @param callable(Server): T $change
     *
     * @return T
     */
    public function underLock(Server $server, callable $change): mixed
    {
        return $this->em->wrapInTransaction(function () use ($server, $change): mixed {
            $this->em->refresh($server, LockMode::PESSIMISTIC_WRITE);

            return $change($server);
        });
    }

    /** Whether evaluate() would change anything, so the minute job only locks servers it changes. */
    public function needsEvaluation(Server $server): bool
    {
        $automaticallyPaused = ServerState::Paused === $server->state && null !== $server->pauseKind && $server->pauseKind->isAutomatic();
        if (ServerState::Listed !== $server->state && !$automaticallyPaused) {
            return false;
        }
        $cause = $this->pauseCause($server);

        return $automaticallyPaused ? (null === $cause || $cause[0] !== $server->pauseKind) : null !== $cause;
    }

    public function evaluate(Server $server): void
    {
        $automaticallyPaused = ServerState::Paused === $server->state && null !== $server->pauseKind && $server->pauseKind->isAutomatic();
        if (ServerState::Listed !== $server->state && !$automaticallyPaused) {
            return;
        }

        $cause = $this->pauseCause($server);
        if (ServerState::Listed === $server->state && null !== $cause) {
            $this->pause($server, $cause[0], $cause[1], null);
            $this->notifier->curators(
                \sprintf('%s was paused automatically', $server->domain),
                \sprintf("%s was paused automatically.\n\nReason: %s\n\nIt returns to the list by itself once the cause is gone.", $server->domain, $cause[1]),
            );

            return;
        }
        if ($automaticallyPaused && null === $cause) {
            $this->resume($server, null, 'The cause of the automatic pause is gone.');

            return;
        }
        if ($automaticallyPaused && null !== $cause && $cause[0] !== $server->pauseKind) {
            $server->pauseKind = $cause[0];
            $server->pauseReason = $cause[1];
        }
    }

    /**
     * @return array{0: PauseKind, 1: string}|null
     */
    private function pauseCause(Server $server): ?array
    {
        $now = $this->clock->now();
        $staleDays = $this->settings->int('pause_after_stale_days');
        if (null === $server->lastHeartbeatAt || $server->lastHeartbeatAt < $now->modify("-{$staleDays} days")) {
            return [PauseKind::Stale, \sprintf('No accepted heartbeat for %d days.', $staleDays)];
        }
        $federationHours = $this->settings->int('pause_after_federation_failing_hours');
        if (null !== $server->federationFailingSince && $server->federationFailingSince <= $now->modify("-{$federationHours} hours")) {
            return [PauseKind::Federation, \sprintf('The server has reported federation_ok: false for %d hours.', $federationHours)];
        }
        $graceDays = $this->settings->int('min_version_grace_days');
        if (null !== $server->belowMinVersionSince && $server->belowMinVersionSince <= $now->modify("-{$graceDays} days")) {
            return [PauseKind::Version, \sprintf('Version %s is below the minimum %s.', (string) $server->softwareVersion, $this->settings->minServerVersion)];
        }
        // Filter in PHP: the identity map then reflects results recorded in this unit of work.
        foreach ($this->em->getRepository(ServerCheck::class)->findBy(['server' => $server]) as $check) {
            if (\in_array($check->name, Checks::CRITICAL, true) && !$check->passed
                && $check->consecutiveFailures >= $this->settings->int('check_failures_before_pause')) {
                return [PauseKind::Checks, \sprintf('Check "%s" failed: %s', $check->name, $check->message)];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------
    // Transition helpers shared with the curation service
    // ---------------------------------------------------------------------------------------

    public function pause(Server $server, PauseKind $kind, string $reason, ?\App\Entity\Curator $by): void
    {
        $now = $this->clock->now();
        $server->state = ServerState::Paused;
        $server->pauseKind = $kind;
        $server->pausedAt = $now;
        $server->pauseReason = $reason;
        $server->updatedAt = $now;

        // Notice to the admin with the specific concern (concept 12.4).
        $this->em->persist(new Comment($server, $by, false, CommentVisibility::Admin, 'Your listing was paused. '.$reason, $now));
        $this->notifier->serverAdmin($server, 'Your listing is paused', "Your server {$server->domain} is paused in the Aloha Directory.\n\nReason: {$reason}");

        if (null === $by) {
            $this->audit->system('server.paused', $server, ['kind' => $kind->value, 'reason' => $reason]);
        } else {
            $this->audit->curator($by, 'server.paused', $server, ['kind' => $kind->value, 'reason' => $reason]);
        }
    }

    public function resume(Server $server, ?\App\Entity\Curator $by, string $reason): void
    {
        $now = $this->clock->now();
        $previousKind = $server->pauseKind;
        $server->state = ServerState::Listed;
        $server->pauseKind = null;
        $server->pausedAt = null;
        $server->pauseReason = null;
        $server->updatedAt = $now;
        if (null === $by) {
            $this->audit->system('server.resumed', $server, ['previous_kind' => $previousKind?->value, 'reason' => $reason]);
        }
    }

    public function markInReview(Server $server): void
    {
        $now = $this->clock->now();
        $server->state = ServerState::InReview;
        $server->inReviewAt = $now;
        $server->updatedAt = $now;
        $this->audit->system('server.in_review', $server);
    }

    public function markListed(Server $server): void
    {
        $now = $this->clock->now();
        $server->state = ServerState::Listed;
        $server->listedAt ??= $now;
        $server->reviewedAt = $now;
        $server->nextReviewAt = $now->modify('+'.$this->settings->int('review_interval_months').' months');
        $server->pauseKind = null;
        $server->pausedAt = null;
        $server->pauseReason = null;
        // The published profile is the one both curators confirmed (CurationService checks its
        // fingerprint). Pending changes to reviewed groups are never approved implicitly: they keep
        // waiting for approveProfileChanges().
        $server->approvedProfile = $server->profile;
        $server->updatedAt = $now;
    }

    public function markDelisted(Server $server, string $reason): void
    {
        $now = $this->clock->now();
        $server->state = ServerState::Delisted;
        $server->delistedAt = $now;
        $server->decisionReason = $reason;
        $server->featured = false;
        $server->searchProvider = false;
        $server->pauseKind = null;
        $server->updatedAt = $now;
        $this->closeOpenProposals($server, 'The server was delisted.');
    }

    public function markRejected(Server $server, string $reason): void
    {
        $now = $this->clock->now();
        $server->state = ServerState::Rejected;
        $server->rejectedAt = $now;
        $server->decisionReason = $reason;
        $server->updatedAt = $now;
        $this->closeOpenProposals($server, 'The application was rejected.');
    }

    public function markWaitlisted(Server $server): void
    {
        $server->state = ServerState::Waitlisted;
        $server->updatedAt = $this->clock->now();
    }

    private function resetForApplication(Server $server, \DateTimeImmutable $now): void
    {
        $server->state = ServerState::Applied;
        $server->appliedAt = $now;
        $server->inReviewAt = null;
        $server->listedAt = null;
        $server->featured = false;
        $server->featuredAt = null;
        $server->searchProvider = false;
        $server->weightPercent = 100;
        $server->reviewedAt = null;
        $server->nextReviewAt = null;
        $server->pauseKind = null;
        $server->pausedAt = null;
        $server->pauseReason = null;
        $server->withdrawnAt = null;
        $server->rejectedAt = null;
        $server->approvedProfile = null;
        $server->pendingProfile = null;
        $server->profileRequested = null === $server->profile;
    }

    private function closeOpenProposals(Server $server, string $note): void
    {
        $now = $this->clock->now();
        foreach ($this->proposals->findOpen($server) as $proposal) {
            /** @var Proposal $proposal */
            $proposal->status = ProposalStatus::Obsolete;
            $proposal->decidedAt = $now;
            $proposal->decisionNote = $note;
        }
    }

    private function assertActive(Server $server): void
    {
        if (!$server->state->isActive()) {
            throw new ApiProblem(409, 'not_active', \sprintf('The server is %s.', $server->state->label()));
        }
    }
}
