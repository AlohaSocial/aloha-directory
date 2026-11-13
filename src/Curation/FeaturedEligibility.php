<?php

declare(strict_types=1);

namespace App\Curation;

use App\Entity\Server;
use App\Enum\ServerState;
use App\Repository\ComplaintRepository;
use App\Repository\HeartbeatRepository;
use App\Service\Buckets;
use App\Service\DirectorySettings;
use Psr\Clock\ClockInterface;

/**
 * Featured status criteria of concept 7.4.
 */
final class FeaturedEligibility
{
    public function __construct(
        private readonly HeartbeatRepository $heartbeats,
        private readonly ComplaintRepository $complaints,
        private readonly Buckets $buckets,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> unmet criteria; empty when the server may be featured
     */
    public function unmet(Server $server): array
    {
        $now = $this->clock->now();
        $unmet = [];
        if (ServerState::Listed !== $server->state) {
            $unmet[] = 'The server is not listed.';
        }
        $minDays = $this->settings->int('featured_min_listed_days');
        if (null === $server->listedAt || $server->listedAt > $now->modify("-{$minDays} days")) {
            $unmet[] = \sprintf('Listed for less than %d days.', $minDays);
        }
        $availability = $this->availabilityPercent($server);
        if ($availability < $this->settings->int('featured_min_availability_percent')) {
            $unmet[] = \sprintf('Heartbeat availability over the last 30 days is %.1f%% (needs %d%%).', $availability, $this->settings->int('featured_min_availability_percent'));
        }
        if ('long' === $this->buckets->approvalWait($server)) {
            $unmet[] = 'The approval backlog is long.';
        }
        $open = \count($this->complaints->findOpen($server));
        if ($open > 0) {
            $unmet[] = \sprintf('%d unresolved complaint(s).', $open);
        }

        return $unmet;
    }

    /**
     * Share of hours in the last 30 days (or since listing) with at least one accepted heartbeat.
     */
    public function availabilityPercent(Server $server): float
    {
        $now = $this->clock->now();
        $since = $now->modify('-30 days');
        if (null !== $server->listedAt && $server->listedAt > $since) {
            $since = $server->listedAt;
        }
        $hours = max(1, intdiv($now->getTimestamp() - $since->getTimestamp(), 3600));
        $seen = [];
        foreach ($this->heartbeats->receiveTimesSince($server, $since) as $time) {
            $seen[intdiv($time->getTimestamp() - $since->getTimestamp(), 3600)] = true;
        }

        return min(100.0, 100.0 * \count($seen) / $hours);
    }
}
