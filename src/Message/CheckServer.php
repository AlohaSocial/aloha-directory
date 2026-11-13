<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Run the automatic checks of one server (concept 7.2), in the background.
 */
final readonly class CheckServer
{
    public function __construct(
        public int $serverId,
    ) {
    }
}
