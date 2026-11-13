<?php

declare(strict_types=1);

namespace App\Enum;

enum ComplaintStatus: string
{
    case Open = 'open';
    /** Shared with the server admin (without the reporter's identity). */
    case Shared = 'shared';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function isOpen(): bool
    {
        return self::Open === $this || self::Shared === $this;
    }
}
