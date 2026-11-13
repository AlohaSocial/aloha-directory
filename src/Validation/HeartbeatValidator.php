<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * The heartbeat of concept 6.3 (plus the optional search load hint of 10.5 and atproto block of 15.1).
 */
final class HeartbeatValidator
{
    private const MAX_COUNT = 100_000_000;

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws ValidationFailed
     */
    public function validate(array $input): array
    {
        $in = new InputReader($input);

        $sentAt = null;
        $sentAtRaw = $in->string('sent_at', 40);
        if (null !== $sentAtRaw) {
            $sentAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $sentAtRaw)
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $sentAtRaw)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $sentAtRaw, new \DateTimeZone('UTC'));
            if (false === $sentAt) {
                $in->error('sent_at', 'must be an RFC 3339 timestamp');
                $sentAt = null;
            }
        }

        $version = $in->string('software.version', 32);
        if (null !== $version && 1 !== preg_match('/\A\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]{1,20})?\z/', $version)) {
            $in->error('software.version', 'must be a semantic version');
            $version = null;
        }

        $heartbeat = [
            'domain' => $in->string('domain', 253),
            'software' => [
                'name' => $in->string('software.name', 64),
                'version' => $version,
                'nextcloud' => $in->string('software.nextcloud', 32, false),
            ],
            'signup' => [
                'open' => $in->bool('signup.open'),
                'mode' => $in->choice('signup.mode', ['open', 'approval', 'invite', 'closed']),
                'available_accounts' => $in->int('signup.available_accounts', 0, self::MAX_COUNT),
                'approval_backlog' => $in->int('signup.approval_backlog', 0, self::MAX_COUNT),
            ],
            'usage' => [
                'users_total' => $in->int('usage.users_total', 0, self::MAX_COUNT, false),
                'users_active_month' => $in->int('usage.users_active_month', 0, self::MAX_COUNT, false),
            ],
            'search' => [
                'accounts' => $in->bool('search.accounts', false) ?? false,
                'hashtags' => $in->bool('search.hashtags', false) ?? false,
                'statuses' => $in->bool('search.statuses', false) ?? false,
                'trends' => $in->bool('search.trends', false) ?? false,
                'load' => $in->float('search.load', 0.0, 1.0, false),
                'providers_used' => $in->int('search.providers_used', 0, 1000, false),
            ],
            'health' => [
                'queue_lag_seconds' => $in->int('health.queue_lag_seconds', 0, 365 * 86400, false),
                'federation_ok' => $in->bool('health.federation_ok'),
            ],
            'profile_etag' => $in->string('profile_etag', 128, false),
            'sent_at' => $sentAt,
        ];

        if (null !== $in->raw('atproto')) {
            $relay = $in->string('atproto.relay_last_crawl', 40, false);
            if (null !== $relay && false === \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $relay)) {
                $in->error('atproto.relay_last_crawl', 'must be an RFC 3339 timestamp');
            }
            $heartbeat['atproto'] = [
                'enabled' => $in->bool('atproto.enabled'),
                'handles_ok' => $in->bool('atproto.handles_ok', false) ?? false,
                'relay_last_crawl' => $relay,
            ];
        }

        if ([] !== $in->errors()) {
            throw new ValidationFailed($in->errors());
        }

        return $heartbeat;
    }
}
