<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Listing states of concept 7.1. "Full" and "Featured" are not states of their own: "full" is
 * derived from the heartbeat and "featured" is a flag on a listed server. Enrolments whose domain
 * proof is still outstanding are App\Entity\PendingEnrolment records, not servers.
 */
enum ServerState: string
{
    case Applied = 'applied';
    case InReview = 'in_review';
    /** A good server that adds nothing new to the mix right now (concept 7.3). */
    case Waitlisted = 'waitlisted';
    case Listed = 'listed';
    case Paused = 'paused';
    case Delisted = 'delisted';
    case Withdrawn = 'withdrawn';
    case Rejected = 'rejected';

    /** States in which a verified server takes part: it may send profiles and heartbeats. */
    public function isActive(): bool
    {
        return \in_array($this, [self::Applied, self::InReview, self::Waitlisted, self::Listed, self::Paused], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::InReview => 'In review',
            self::Waitlisted => 'Waiting list',
            self::Listed => 'Listed',
            self::Paused => 'Paused',
            self::Delisted => 'Delisted',
            self::Withdrawn => 'Withdrawn',
            self::Rejected => 'Rejected',
        };
    }
}
