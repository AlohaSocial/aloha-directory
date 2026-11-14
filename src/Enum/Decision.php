<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Decisions a curator makes about a server (concept 4.1, 13.4). One curator decides, and the
 * decision takes effect at once; it is recorded with its reason in the audit log.
 */
enum Decision: string
{
    case List = 'list';
    case Feature = 'feature';
    case Unfeature = 'unfeature';
    case Delist = 'delist';
    case Weight = 'weight';
    case Reject = 'reject';
    case EnableSearch = 'enable_search';
    case DisableSearch = 'disable_search';

    public function label(): string
    {
        return match ($this) {
            self::List => 'List',
            self::Feature => 'Feature',
            self::Unfeature => 'Remove featured status',
            self::Delist => 'Delist',
            self::Weight => 'Change weight',
            self::Reject => 'Reject application',
            self::EnableSearch => 'Accept as search provider',
            self::DisableSearch => 'Remove as search provider',
        };
    }
}
