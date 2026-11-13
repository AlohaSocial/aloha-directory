<?php

declare(strict_types=1);

namespace App\Lists;

use App\Entity\Server;
use App\Enum\ServerState;
use App\Repository\ServerRepository;
use App\Service\Buckets;
use App\Service\DirectorySettings;
use Psr\Clock\ClockInterface;

/**
 * Builds the entries of servers.json (concept Appendix A.2) from the database. Only listed servers
 * appear; exact heartbeat numbers never leave the directory, only buckets (concept 13.3).
 */
final class ServerListEntryFactory
{
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly Buckets $buckets,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->servers->findInStates(ServerState::Listed) as $server) {
            if (null === $server->profile) {
                continue;
            }
            $entries[] = $this->entry($server);
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['domain'], $b['domain']));

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(Server $server): array
    {
        $p = $server->profile ?? [];
        $now = $this->clock->now();

        $staleHours = $this->settings->int('stale_after_hours');
        $stale = null === $server->lastHeartbeatAt || $server->lastHeartbeatAt < $now->modify("-{$staleHours} hours");
        $full = $server->isFull();
        $signupState = match (true) {
            $stale => 'unavailable',
            $full => 'closed',
            default => 'open',
        };
        $capacity = $stale ? 'full' : $this->buckets->capacity($server);

        $entry = [
            'domain' => $server->domain,
            'name' => $p['identity']['name'] ?? $server->domain,
            'description' => $p['identity']['description'] ?? '',
            'long_description' => $p['identity']['long_description'] ?? null,
            'logo' => null !== $server->logoHash ? 'sha256:'.$server->logoHash : null,
            'banner' => null !== $server->bannerHash ? 'sha256:'.$server->bannerHash : null,
            'languages' => $p['audience']['languages'] ?? [],
            'topic' => $p['audience']['topic'] ?? 'general',
            'audience' => $p['audience']['audience'] ?? null,
            'region' => [
                'hosting' => $p['jurisdiction']['hosting_country'] ?? null,
                'operator_seat' => $p['jurisdiction']['operator_seat'] ?? null,
                'gdpr' => $p['jurisdiction']['gdpr'] ?? null,
            ],
            'operator' => self::withoutNulls([
                'name' => $p['operator']['name'] ?? null,
                'type' => $p['operator']['type'] ?? null,
                'website' => $p['operator']['website'] ?? null,
                'funding' => $p['operator']['funding'] ?? null,
                'commitment_until' => $p['operator']['commitment_until'] ?? null,
                'shutdown_notice_months' => $p['operator']['shutdown_notice_months'] ?? null,
            ]),
            'moderation' => self::withoutNulls([
                'moderators' => $p['moderation']['moderators'] ?? null,
                'response_hours' => $p['moderation']['response_hours'] ?? null,
                'policy_url' => $p['moderation']['policy_url'] ?? null,
            ]),
            'terms_url' => $p['terms']['terms_url'] ?? null,
            'privacy_url' => $p['terms']['privacy_url'] ?? null,
            'rules' => $p['terms']['rules'] ?? [],
            'min_age' => $p['terms']['min_age'] ?? null,
            'signup' => [
                'mode' => $server->signupMode ?? ($p['account']['signup_mode'] ?? 'approval'),
                'email_required' => $p['account']['email_required'] ?? true,
                'state' => $signupState,
                'capacity' => $capacity,
                'approval_wait' => $this->buckets->approvalWait($server),
            ],
            'account' => self::withoutNulls([
                'storage_mb' => $p['account']['storage_mb'] ?? null,
                'media_mb' => $p['account']['media_mb'] ?? null,
                'features' => $p['account']['features'] ?? [],
            ]),
            'state' => match (true) {
                $full || $stale => 'full',
                $server->featured => 'featured',
                default => 'listed',
            },
            'weight' => round($server->weight(), 2),
            'balance' => round($server->balancePercent / 100, 2),
            'listed_since' => $server->listedAt?->format('Y-m-d'),
            'reviewed_at' => $server->reviewedAt?->format('Y-m-d'),
        ];

        // Bluesky support is published only when the automatic checks confirmed it (concept 15.1).
        if (true === ($p['atproto']['enabled'] ?? false) && true === $server->atprotoVerified) {
            $entry['atproto'] = self::withoutNulls([
                'enabled' => true,
                'handle_example' => $p['atproto']['handle_example'] ?? null,
                'move_away' => $p['atproto']['move_away'] ?? false,
            ]);
        }

        return self::withoutNulls($entry);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => null !== $v);
    }
}
