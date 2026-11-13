<?php

declare(strict_types=1);

namespace App\Enum;

enum PauseKind: string
{
    /** No accepted heartbeat for 7 days (concept 6.4). */
    case Stale = 'stale';
    /** A critical automatic check (TLS, nodeinfo) keeps failing. */
    case Checks = 'checks';
    /** federation_ok false for 24 hours. */
    case Federation = 'federation';
    /** Below the minimum server version after the 30-day warning (concept 13.6). */
    case Version = 'version';
    /** A curator paused the server during an investigation (concept 7.5). */
    case Curator = 'curator';

    /** Automatic pauses lift themselves once the cause is gone; a curator pause needs two curators to lift. */
    public function isAutomatic(): bool
    {
        return self::Curator !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Stale => 'No heartbeat for 7 days',
            self::Checks => 'Automatic checks failing',
            self::Federation => 'Federation failing for 24 hours',
            self::Version => 'Below the minimum version',
            self::Curator => 'Paused by a curator',
        };
    }
}
