<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class Fixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function profile(string $domain = 'social.example.org'): array
    {
        return [
            'identity' => [
                'name' => 'Example Social',
                'description' => 'A friendly general-purpose server for people in Hamburg.',
                'long_description' => "We are a small association.\nEveryone is welcome.",
                'logo_url' => "https://{$domain}/logo.png",
            ],
            'terms' => [
                'terms_url' => "https://{$domain}/terms",
                'privacy_url' => "https://{$domain}/privacy",
                'rules' => ['Be kind.', 'No harassment.', 'No illegal content.'],
                'min_age' => 16,
            ],
            'audience' => ['languages' => ['de', 'en'], 'topic' => 'general', 'audience' => 'People in Hamburg'],
            'jurisdiction' => ['hosting_country' => 'DE', 'operator_seat' => 'DE', 'gdpr' => true, 'dpa_url' => null],
            'operator' => [
                'name' => 'Example e.V.', 'type' => 'association', 'website' => 'https://example.org',
                'funding' => 'Donations and membership fees', 'commitment_until' => '2028-12', 'shutdown_notice_months' => 3,
            ],
            'moderation' => [
                'moderators' => 4, 'abuse_email' => "abuse@{$domain}", 'abuse_actor' => null, 'response_hours' => 24,
                'blocklist_policy' => 'We follow the Oliphant tier-0 list.', 'policy_url' => "https://{$domain}/rules",
            ],
            'account' => ['signup_mode' => 'approval', 'email_required' => true, 'storage_mb' => 2048, 'media_mb' => 40, 'features' => ['video', 'shorts', 'dm']],
            'search' => [
                'capabilities' => ['accounts', 'hashtags'], 'index_policy_url' => "https://{$domain}/index-policy",
                'log_retention_days' => 7, 'rate_limit_per_minute' => 60,
            ],
            'etag' => 'W/"7c1e"',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function heartbeat(string $domain, \DateTimeImmutable $now, int $available = 412, int $backlog = 3, bool $open = true): array
    {
        return [
            'domain' => $domain,
            'software' => ['name' => 'aloha', 'version' => '0.27.0', 'nextcloud' => '33.0.2'],
            'signup' => ['open' => $open, 'mode' => 'approval', 'available_accounts' => $available, 'approval_backlog' => $backlog],
            'usage' => ['users_total' => 1588, 'users_active_month' => 940],
            'search' => ['accounts' => true, 'hashtags' => true, 'statuses' => false, 'trends' => false, 'load' => 0.2],
            'health' => ['queue_lag_seconds' => 12, 'federation_ok' => true],
            'profile_etag' => 'W/"7c1e"',
            'sent_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
