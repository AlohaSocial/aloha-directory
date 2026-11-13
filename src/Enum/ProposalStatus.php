<?php

declare(strict_types=1);

namespace App\Enum;

enum ProposalStatus: string
{
    case Open = 'open';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    /** The server's state changed so the proposal no longer applies. */
    case Obsolete = 'obsolete';
}
