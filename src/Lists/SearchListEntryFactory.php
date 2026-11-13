<?php

declare(strict_types=1);

namespace App\Lists;

use App\Enum\ServerState;
use App\Repository\ServerRepository;
use App\Service\DirectorySettings;
use Psr\Clock\ClockInterface;

/**
 * Builds the entries of search.json (concept 10, Appendix A.3, 15.3).
 *
 * A provider appears only while it is listed, accepted as a provider by two curators, has a fresh
 * heartbeat, and actually has the capabilities switched on that its profile offers.
 */
final class SearchListEntryFactory
{
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $now = $this->clock->now();
        $staleHours = $this->settings->int('stale_after_hours');
        $entries = [];

        foreach ($this->servers->findInStates(ServerState::Listed) as $server) {
            if (!$server->searchProvider || null === $server->profile) {
                continue;
            }
            if (null === $server->lastHeartbeatAt || $server->lastHeartbeatAt < $now->modify("-{$staleHours} hours")) {
                continue;
            }
            $offered = $server->profile['search']['capabilities'] ?? [];
            $capabilities = array_values(array_intersect($offered, $server->heartbeatSearch ?? []));
            if ([] === $capabilities) {
                continue;
            }

            // The load hint lowers the weight of a provider under strain (concept 10.5).
            $load = $server->searchLoad ?? 0.0;
            $loadFactor = match (true) {
                $load >= 0.95 => 0.25,
                $load >= 0.8 => 0.5,
                default => 1.0,
            };

            $entries[] = [
                'kind' => 'aloha',
                'domain' => $server->domain,
                'capabilities' => $capabilities,
                'languages' => $server->profile['audience']['languages'] ?? [],
                'rate_limit_per_minute' => $server->profile['search']['rate_limit_per_minute'] ?? 60,
                'index_policy_url' => $server->profile['search']['index_policy_url'] ?? null,
                'log_retention_days' => $server->profile['search']['log_retention_days'] ?? 7,
                'weight' => round(max(0.1, $server->weight() * $loadFactor), 2),
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['domain'], $b['domain']));

        // Bluesky search goes through an AppView named here, never hard-coded in the server app (concept 15.3).
        if (null !== $this->settings->atprotoAppViewUrl && '' !== $this->settings->atprotoAppViewUrl) {
            $entries[] = [
                'kind' => 'atproto_appview',
                'url' => rtrim($this->settings->atprotoAppViewUrl, '/'),
                'capabilities' => ['accounts'],
                'methods' => ['app.bsky.actor.searchActorsTypeahead', 'app.bsky.actor.searchActors'],
                'authenticated' => false,
            ];
        }

        return array_map(static fn (array $e): array => array_filter($e, static fn (mixed $v): bool => null !== $v), $entries);
    }
}
