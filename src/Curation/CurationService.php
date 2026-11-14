<?php

declare(strict_types=1);

namespace App\Curation;

use App\Entity\Comment;
use App\Entity\Complaint;
use App\Entity\Curator;
use App\Entity\CuratorConflict;
use App\Entity\Review;
use App\Entity\Server;
use App\Entity\TransparencyEntry;
use App\Enum\CommentVisibility;
use App\Enum\ComplaintStatus;
use App\Enum\Decision;
use App\Enum\PauseKind;
use App\Enum\ServerState;
use App\Service\AuditLogger;
use App\Service\DirectorySettings;
use App\Service\Notifier;
use App\Service\ServerLifecycle;
use App\Validation\InputReader;
use App\Validation\ProfileValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Curator decisions (concept 7, 12.4, 13.4).
 *
 *  - One curator decides: listing, featuring, delisting, weight changes, rejections, search-provider
 *    status and pauses take effect at once, with the reason in the audit log.
 *  - Listing an application needs that curator's complete review of the current profile.
 *  - A curator with a declared conflict of interest cannot decide anything about that server.
 *
 * Every method flushes, so each action and its audit entry are one transaction.
 */
final class CurationService
{
    private const REVIEW_VALID_DAYS = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServerLifecycle $lifecycle,
        private readonly FeaturedEligibility $featured,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    // --- Decisions ---------------------------------------------------------------------------

    /**
     * Makes a decision about a server. It takes effect at once; the published lists follow within
     * a minute.
     *
     * @param array<string, mixed> $params weight, rule, public_reason (depending on the decision)
     */
    public function decide(Curator $curator, Server $server, Decision $decision, array $params, string $reason): void
    {
        $this->assertNoConflict($curator, $server);
        $reason = $this->requireText($reason, 'reason', 2000);
        $params = $this->validateParams($decision, $params);
        $this->assertApplicable($server, $decision, $params);
        if (Decision::List === $decision && self::isApplication($server)) {
            // The curator's own complete review of exactly the current profile.
            $this->assertRecentReview($server, $curator, Review::KIND_INITIAL);
        }
        if (Decision::Feature === $decision && [] !== ($unmet = $this->featured->unmet($server))) {
            throw new CurationException('The server cannot be featured yet: '.implode(' ', $unmet));
        }

        $this->execute($curator, $server, $decision, $params, $reason);
        $this->em->flush();
    }

    // --- Pauses, waiting list and notes --------------------------------------------------------

    /**
     * Immediate pause during an investigation (concept 7.5). The admin gets the concern at once.
     */
    public function pause(Curator $curator, Server $server, string $concern): void
    {
        $this->assertNoConflict($curator, $server);
        $concern = $this->requireText($concern, 'concern', 2000);
        if (ServerState::Listed === $server->state) {
            $this->lifecycle->pause($server, PauseKind::Curator, $concern, $curator);
        } elseif (ServerState::Paused === $server->state && PauseKind::Curator !== $server->pauseKind) {
            // Keep it paused even when the automatic cause goes away.
            $this->lifecycle->pause($server, PauseKind::Curator, $concern, $curator);
        } else {
            throw new CurationException('Only listed or automatically paused servers can be paused by a curator.');
        }
        $this->em->flush();
    }

    public function waitlist(Curator $curator, Server $server, string $note): void
    {
        $this->assertNoConflict($curator, $server);
        if (ServerState::InReview !== $server->state) {
            throw new CurationException('Only applications in review can be put on the waiting list.');
        }
        $note = $this->requireText($note, 'note', 2000);
        $this->lifecycle->markWaitlisted($server);
        $this->em->persist(new Comment($server, $curator, false, CommentVisibility::Admin, 'Your application is on the waiting list. '.$note, $this->clock->now()));
        $this->audit->curator($curator, 'server.waitlisted', $server, ['note' => $note]);
        $this->em->flush();
    }

    public function comment(Curator $curator, Server $server, string $body, CommentVisibility $visibility): void
    {
        $this->assertNoConflict($curator, $server);
        $body = $this->requireText($body, 'comment', 5000, true);
        $this->em->persist(new Comment($server, $curator, false, $visibility, $body, $this->clock->now()));
        $this->audit->curator($curator, CommentVisibility::Admin === $visibility ? 'server.question_sent' : 'server.note_added', $server);
        if (CommentVisibility::Admin === $visibility) {
            $this->notifier->serverAdmin($server, 'A question from the curators', "The curators of the Aloha Directory wrote about {$server->domain}:\n\n{$body}");
        }
        $this->em->flush();
    }

    /**
     * @param array<string, bool> $checklist
     * @param string|null         $seenFingerprint Server::profileFingerprint() of the profile the curator looked at
     */
    public function recordReview(Curator $curator, Server $server, string $kind, array $checklist, string $notes, ?string $seenFingerprint = null): Review
    {
        $this->assertNoConflict($curator, $server);
        if (null !== $seenFingerprint && !hash_equals($server->profileFingerprint(), $seenFingerprint)) {
            throw new CurationException('The server changed its terms, jurisdiction, operator or moderation details while you were reviewing. Please look at the profile again.');
        }
        $items = match ($kind) {
            Review::KIND_INITIAL => Covenant::INITIAL_CHECKLIST,
            Review::KIND_REREVIEW => Covenant::REREVIEW_CHECKLIST,
            default => throw new CurationException('Unknown review kind.'),
        };
        $clean = [];
        foreach (array_keys($items) as $key) {
            $clean[$key] = true === ($checklist[$key] ?? false);
        }
        $notes = $this->requireText($notes, 'notes', 5000, true);
        if (Review::KIND_REREVIEW === $kind && !\in_array($server->state, [ServerState::Listed, ServerState::Paused], true)) {
            throw new CurationException('Re-reviews are for listed servers.');
        }

        $review = new Review($server, $curator, $kind, $clean, $notes, $this->clock->now());
        $review->profileFingerprint = $server->profileFingerprint();
        $this->em->persist($review);
        $passed = !\in_array(false, $clean, true);
        if (Review::KIND_REREVIEW === $kind && $passed) {
            $server->reviewedAt = $this->clock->now();
            $server->nextReviewAt = $this->clock->now()->modify('+'.$this->settings->int('review_interval_months').' months');
        }
        $this->audit->curator($curator, 'review.recorded', $server, ['kind' => $kind, 'passed' => $passed, 'checklist' => $clean]);
        $this->em->flush();

        return $review;
    }

    /**
     * Light re-review of changed terms, jurisdiction, operator or moderation (concept 6.2).
     */
    /**
     * @param string $seenFingerprint Server::pendingProfileFingerprint() of the changes the curator looked at
     */
    public function approveProfileChanges(Curator $curator, Server $server, string $seenFingerprint): void
    {
        $this->assertNoConflict($curator, $server);
        if (null === $server->pendingProfile) {
            throw new CurationException('There are no profile changes waiting.');
        }
        if (!hash_equals((string) $server->pendingProfileFingerprint(), $seenFingerprint)) {
            throw new CurationException('The server sent newer profile changes in the meantime. Please look at them again.');
        }
        $profile = $server->profile ?? [];
        foreach (ProfileValidator::REVIEWED_GROUPS as $group) {
            $profile[$group] = $server->pendingProfile[$group] ?? null;
        }
        $server->profile = $profile;
        $server->approvedProfile = $profile;
        $server->pendingProfile = null;
        $server->updatedAt = $this->clock->now();
        $this->em->persist(new Comment($server, $curator, false, CommentVisibility::Admin, 'The curators accepted your profile changes.', $this->clock->now()));
        $this->audit->curator($curator, 'profile.changes_approved', $server);
        $this->em->flush();
    }

    public function rejectProfileChanges(Curator $curator, Server $server, string $reason): void
    {
        $this->assertNoConflict($curator, $server);
        if (null === $server->pendingProfile) {
            throw new CurationException('There are no profile changes waiting.');
        }
        $reason = $this->requireText($reason, 'reason', 2000);
        $server->pendingProfile = null;
        $this->em->persist(new Comment($server, $curator, false, CommentVisibility::Admin, 'The curators did not accept your profile changes. '.$reason, $this->clock->now()));
        $this->audit->curator($curator, 'profile.changes_rejected', $server, ['reason' => $reason]);
        $this->em->flush();
    }

    /**
     * Reverts descriptions and images that went live at once (concept 6.2) to the approved version.
     */
    public function revertLiveChanges(Curator $curator, Server $server, string $reason): void
    {
        $this->assertNoConflict($curator, $server);
        if (null === $server->approvedProfile) {
            throw new CurationException('There is no approved profile to revert to.');
        }
        $reason = $this->requireText($reason, 'reason', 2000);
        $profile = $server->profile ?? [];
        $oldIdentity = $profile['identity'] ?? [];
        foreach (ProfileValidator::LIVE_GROUPS as $group) {
            if (\array_key_exists($group, $server->approvedProfile)) {
                $profile[$group] = $server->approvedProfile[$group];
            } else {
                unset($profile[$group]);
            }
        }
        $server->profile = $profile;
        foreach (['logo' => 'logo_url', 'banner' => 'banner_url'] as $kind => $field) {
            if (($oldIdentity[$field] ?? null) !== ($profile['identity'][$field] ?? null)) {
                $pending = $server->mediaPending ?? [];
                if (null === ($profile['identity'][$field] ?? null)) {
                    'logo' === $kind ? $server->logoHash = null : $server->bannerHash = null;
                    unset($pending[$kind]);
                } else {
                    $pending[$kind] = $profile['identity'][$field];
                }
                $server->mediaPending = [] === $pending ? null : $pending;
            }
        }
        $server->updatedAt = $this->clock->now();
        $this->em->persist(new Comment($server, $curator, false, CommentVisibility::Admin, 'The curators reverted recent changes to your description or images. '.$reason, $this->clock->now()));
        $this->audit->curator($curator, 'profile.live_changes_reverted', $server, ['reason' => $reason]);
        $this->em->flush();
    }

    // --- Complaints (concept 7.5) ------------------------------------------------------------

    public function shareComplaint(Curator $curator, Complaint $complaint): void
    {
        $this->assertNoConflict($curator, $complaint->server);
        if (ComplaintStatus::Open !== $complaint->status) {
            throw new CurationException('Only open complaints can be shared.');
        }
        $complaint->status = ComplaintStatus::Shared;
        $complaint->sharedAt = $this->clock->now();
        $this->audit->curator($curator, 'complaint.shared', $complaint->server, ['complaint' => $complaint->id]);
        $this->notifier->serverAdmin($complaint->server, 'A complaint about your server', "Someone reported {$complaint->server->domain} to the Aloha Directory. You can read the complaint and respond in the Aloha directory section of your admin settings.");
        $this->em->flush();
    }

    public function replyToComplaint(Curator $curator, Complaint $complaint, string $body): void
    {
        $this->assertNoConflict($curator, $complaint->server);
        if (null === $complaint->sharedAt) {
            throw new CurationException('Share the complaint with the admin first.');
        }
        $body = $this->requireText($body, 'reply', 5000, true);
        $this->em->persist(new Comment($complaint->server, $curator, false, CommentVisibility::Admin, $body, $this->clock->now(), $complaint));
        $this->audit->curator($curator, 'complaint.replied', $complaint->server, ['complaint' => $complaint->id]);
        $this->em->flush();
    }

    public function closeComplaint(Curator $curator, Complaint $complaint, bool $dismiss, string $resolution): void
    {
        $this->assertNoConflict($curator, $complaint->server);
        if (!$complaint->status->isOpen()) {
            throw new CurationException('This complaint is already closed.');
        }
        $complaint->status = $dismiss ? ComplaintStatus::Dismissed : ComplaintStatus::Resolved;
        $complaint->closedAt = $this->clock->now();
        $complaint->closedBy = $curator;
        $complaint->resolution = $this->requireText($resolution, 'resolution', 2000);
        $this->audit->curator($curator, $dismiss ? 'complaint.dismissed' : 'complaint.resolved', $complaint->server, ['complaint' => $complaint->id]);
        $this->em->flush();
    }

    // --- Conflicts of interest --------------------------------------------------------------

    public function declareConflict(Curator $actor, Curator $curator, Server $server, string $note): void
    {
        if ($actor->id !== $curator->id && !$actor->isAdmin()) {
            throw new CurationException('Only admins can record conflicts for other curators.');
        }
        if (null !== $this->conflict($curator, $server)) {
            return;
        }
        $this->em->persist(new CuratorConflict($curator, $server, mb_substr(trim($note), 0, 500), $this->clock->now()));
        $this->audit->curator($actor, 'conflict.declared', $server, ['curator' => $curator->email, 'note' => $note]);
        $this->em->flush();
    }

    public function removeConflict(Curator $actor, CuratorConflict $conflict): void
    {
        if (!$actor->isAdmin()) {
            throw new CurationException('Only admins can remove a conflict of interest.');
        }
        if ($conflict->curator->id === $actor->id) {
            throw new CurationException('Another admin has to remove your own conflict of interest.');
        }
        $this->audit->curator($actor, 'conflict.removed', $conflict->server, ['curator' => $conflict->curator->email]);
        $this->em->remove($conflict);
        $this->em->flush();
    }

    public function conflict(Curator $curator, Server $server): ?CuratorConflict
    {
        return $this->em->getRepository(CuratorConflict::class)->findOneBy(['curator' => $curator, 'server' => $server]);
    }

    // --- Internals -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     */
    private function execute(Curator $curator, Server $server, Decision $decision, array $params, string $reason): void
    {
        $now = $this->clock->now();

        switch ($decision) {
            case Decision::List:
                $wasPaused = ServerState::Paused === $server->state;
                $this->lifecycle->markListed($server);
                $this->audit->curator($curator, $wasPaused ? 'server.resumed' : 'server.listed', $server, ['reason' => $reason]);
                $this->notifier->serverAdmin($server, $wasPaused ? 'Your listing is active again' : 'Your server is listed',
                    $wasPaused ? "{$server->domain} is listed in the Aloha Directory again." : "Welcome! {$server->domain} is now listed in the Aloha Directory. New people can find it in the Aloha apps within a few minutes.");
                break;
            case Decision::Feature:
                $server->featured = true;
                $server->featuredAt = $now;
                $this->audit->curator($curator, 'server.featured', $server, ['reason' => $reason]);
                break;
            case Decision::Unfeature:
                $server->featured = false;
                $server->featuredAt = null;
                $this->audit->curator($curator, 'server.unfeatured', $server, ['reason' => $reason]);
                break;
            case Decision::Weight:
                $old = $server->weightPercent;
                $server->weightPercent = (int) $params['weight_percent'];
                $this->audit->curator($curator, 'server.weight_changed', $server, ['from' => $old / 100, 'to' => $server->weight(), 'reason' => $reason]);
                break;
            case Decision::Delist:
                $this->lifecycle->markDelisted($server, (string) $params['public_reason']);
                $this->em->persist(new TransparencyEntry($server->domain, 'delisted', (string) $params['rule'], (string) $params['public_reason'], $now));
                $this->audit->curator($curator, 'server.delisted', $server, ['rule' => $params['rule'], 'public_reason' => $params['public_reason'], 'reason' => $reason]);
                $this->notifier->serverAdmin($server, 'Your server was delisted', "{$server->domain} was removed from the Aloha Directory.\n\nReason: {$params['public_reason']}\n\nYou can appeal to the advisory board within 30 days.");
                break;
            case Decision::Reject:
                $this->lifecycle->markRejected($server, (string) $params['public_reason']);
                $this->audit->curator($curator, 'server.rejected', $server, ['public_reason' => $params['public_reason'], 'reason' => $reason]);
                $this->notifier->serverAdmin($server, 'Your application was not accepted', "The curators did not accept {$server->domain} for the Aloha Directory.\n\nReason: {$params['public_reason']}");
                break;
            case Decision::EnableSearch:
                $server->searchProvider = true;
                $this->audit->curator($curator, 'server.search_enabled', $server, ['reason' => $reason]);
                break;
            case Decision::DisableSearch:
                $server->searchProvider = false;
                $this->audit->curator($curator, 'server.search_disabled', $server, ['reason' => $reason]);
                break;
        }
        $server->updatedAt = $now;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function validateParams(Decision $decision, array $params): array
    {
        switch ($decision) {
            case Decision::Weight:
                $weight = $params['weight'] ?? null;
                if (!is_numeric($weight)) {
                    throw new CurationException('The weight must be a number between 0.5 and 2.');
                }
                $percent = (int) round(((float) $weight) * 100);
                if ($percent < 50 || $percent > 200 || 0 !== $percent % 5) {
                    throw new CurationException('The weight must be between 0.5 and 2, in steps of 0.05.');
                }

                return ['weight_percent' => $percent];
            case Decision::Delist:
                $rule = (string) ($params['rule'] ?? '');
                if (!\array_key_exists($rule, Covenant::delistingReasons())) {
                    throw new CurationException('Choose the rule that was broken.');
                }

                return ['rule' => $rule, 'public_reason' => $this->requireText((string) ($params['public_reason'] ?? ''), 'public reason', 1000)];
            case Decision::Reject:
                return ['public_reason' => $this->requireText((string) ($params['public_reason'] ?? ''), 'reason for the admin', 1000)];
            default:
                return [];
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertApplicable(Server $server, Decision $decision, array $params): void
    {
        $state = $server->state;
        $ok = match ($decision) {
            Decision::List => \in_array($state, [ServerState::InReview, ServerState::Waitlisted], true)
                || (ServerState::Paused === $state && PauseKind::Curator === $server->pauseKind),
            Decision::Feature => ServerState::Listed === $state && !$server->featured,
            Decision::Unfeature => $server->featured,
            Decision::Delist => \in_array($state, [ServerState::Listed, ServerState::Paused], true),
            Decision::Weight => \in_array($state, [ServerState::Listed, ServerState::Paused], true) && $params['weight_percent'] !== $server->weightPercent,
            Decision::Reject => \in_array($state, [ServerState::Applied, ServerState::InReview, ServerState::Waitlisted], true),
            Decision::EnableSearch => ServerState::Listed === $state && !$server->searchProvider && [] !== ($server->profile['search']['capabilities'] ?? []),
            Decision::DisableSearch => $server->searchProvider,
        };
        if (!$ok) {
            throw new CurationException(\sprintf('"%s" does not apply to %s in its current state (%s).', $decision->label(), $server->domain, $server->state->label()));
        }
        if (Decision::List === $decision && null === $server->profile) {
            throw new CurationException('The server has not sent a profile yet.');
        }
    }

    private function assertRecentReview(Server $server, Curator $curator, string $kind): void
    {
        $review = $this->em->getRepository(Review::class)->findOneBy(['server' => $server, 'curator' => $curator, 'kind' => $kind], ['createdAt' => 'DESC']);
        if (null === $review || $review->createdAt < $this->clock->now()->modify('-'.self::REVIEW_VALID_DAYS.' days') || \in_array(false, $review->checklist, true)) {
            throw new CurationException('Complete the review checklist (all items) before listing this server.');
        }
        if ($review->profileFingerprint !== $server->profileFingerprint()) {
            throw new CurationException('The server changed its terms, jurisdiction, operator or moderation details after your review. Please review it again.');
        }
    }

    private static function isApplication(Server $server): bool
    {
        return \in_array($server->state, [ServerState::InReview, ServerState::Waitlisted], true);
    }

    private function assertNoConflict(Curator $curator, Server $server): void
    {
        if (null !== $this->conflict($curator, $server)) {
            throw new CurationException('You declared a conflict of interest for this server.');
        }
    }

    private function requireText(string $text, string $label, int $max, bool $multiline = true): string
    {
        $clean = InputReader::cleanText($text, $multiline);
        if ('' === $clean) {
            throw new CurationException(\sprintf('Please give a %s.', $label));
        }
        if (mb_strlen($clean) > $max) {
            throw new CurationException(\sprintf('The %s must be at most %d characters.', $label, $max));
        }

        return $clean;
    }
}
