<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Server;

/**
 * Capacity and approval-wait buckets of concept 13.3. The published list carries buckets only, so
 * an hourly heartbeat changes the list only when a server crosses a threshold.
 */
final class Buckets
{
    public const CAPACITY = ['plenty', 'some', 'few', 'full'];
    public const APPROVAL_WAIT = ['none', 'short', 'long'];

    public function __construct(private readonly DirectorySettings $settings)
    {
    }

    public function capacity(Server $server): string
    {
        if (null === $server->availableAccounts || $server->isFull()) {
            return 'full';
        }

        return match (true) {
            $server->availableAccounts >= $this->settings->int('capacity_plenty_min') => 'plenty',
            $server->availableAccounts >= $this->settings->int('capacity_some_min') => 'some',
            default => 'few',
        };
    }

    public function approvalWait(Server $server): string
    {
        if ('open' === $server->signupMode || 0 === (int) $server->approvalBacklog) {
            return 'none';
        }

        return $server->approvalBacklog >= $this->settings->int('approval_long_min') ? 'long' : 'short';
    }
}
