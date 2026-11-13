<?php

declare(strict_types=1);

namespace App\Validation;

use Symfony\Component\Intl\Countries;

/**
 * The listing profile of concept 6.2. Input and stored form use the same grouped shape:
 *
 *   identity, terms, audience, jurisdiction, operator, moderation, account, search, atproto (optional), etag
 *
 * Only known fields are kept. Changes to the groups in REVIEWED_GROUPS send a listed server back to
 * a light re-review; all other groups go live at once (curators can revert them).
 */
final class ProfileValidator
{
    public const REVIEWED_GROUPS = ['terms', 'jurisdiction', 'operator', 'moderation'];
    public const LIVE_GROUPS = ['identity', 'audience', 'account', 'search', 'atproto'];

    public const OPERATOR_TYPES = ['person', 'association', 'company', 'public_body'];
    public const ACCOUNT_FEATURES = ['dm', 'shorts', 'video'];
    public const SEARCH_CAPABILITIES = ['accounts', 'hashtags', 'statuses', 'trends'];
    public const SIGNUP_MODES = ['approval', 'open'];

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

        $profile = [
            'identity' => [
                'name' => $in->string('identity.name', 100),
                'description' => $in->string('identity.description', 280),
                'long_description' => $in->string('identity.long_description', 5000, false, 1, true),
                'logo_url' => $in->url('identity.logo_url', false),
                'banner_url' => $in->url('identity.banner_url', false),
            ],
            'terms' => [
                'terms_url' => $in->url('terms.terms_url'),
                'privacy_url' => $in->url('terms.privacy_url'),
                'rules' => $in->stringList('terms.rules', 50, 1000),
                'min_age' => $in->int('terms.min_age', 13, 21, false),
            ],
            'audience' => [
                'languages' => $this->languages($in),
                'topic' => $this->topic($in),
                'audience' => $in->string('audience.audience', 280, false),
            ],
            'jurisdiction' => [
                'hosting_country' => $this->country($in, 'jurisdiction.hosting_country'),
                'operator_seat' => $this->country($in, 'jurisdiction.operator_seat'),
                'gdpr' => $in->bool('jurisdiction.gdpr'),
                'dpa_url' => $in->url('jurisdiction.dpa_url', false),
            ],
            'operator' => [
                'name' => $in->string('operator.name', 200),
                'type' => $in->choice('operator.type', self::OPERATOR_TYPES),
                'website' => $in->url('operator.website', false),
                'funding' => $in->string('operator.funding', 500),
                'commitment_until' => $this->yearMonth($in, 'operator.commitment_until'),
                'shutdown_notice_months' => $in->int('operator.shutdown_notice_months', 3, 60),
            ],
            'moderation' => [
                'moderators' => $in->int('moderation.moderators', 1, 100000),
                'abuse_email' => $in->email('moderation.abuse_email'),
                'abuse_actor' => $in->url('moderation.abuse_actor', false),
                'response_hours' => $in->int('moderation.response_hours', 1, 336),
                'blocklist_policy' => $in->string('moderation.blocklist_policy', 1000, false, 1, true),
                'policy_url' => $in->url('moderation.policy_url'),
            ],
            'account' => [
                'signup_mode' => $in->choice('account.signup_mode', self::SIGNUP_MODES),
                'email_required' => $in->bool('account.email_required'),
                'storage_mb' => $in->int('account.storage_mb', 0, 10_000_000),
                'media_mb' => $in->int('account.media_mb', 0, 100_000, false),
                'features' => $in->subset('account.features', self::ACCOUNT_FEATURES, false) ?? [],
            ],
            'search' => [
                'capabilities' => $in->subset('search.capabilities', self::SEARCH_CAPABILITIES, false) ?? [],
                'index_policy_url' => $in->url('search.index_policy_url', false),
                'log_retention_days' => $in->int('search.log_retention_days', 0, 7, false),
                'rate_limit_per_minute' => $in->int('search.rate_limit_per_minute', 1, 100_000, false),
            ],
        ];

        // A provider must state its index policy, log retention and rate limit (concept 10.2, 10.4, 10.5).
        if ([] !== $profile['search']['capabilities']) {
            foreach (['index_policy_url', 'log_retention_days', 'rate_limit_per_minute'] as $field) {
                if (null === $profile['search'][$field] && !isset($in->errors()['search.'.$field])) {
                    $in->error('search.'.$field, 'is required when search capabilities are offered');
                }
            }
        }

        if (null !== $in->raw('atproto')) {
            $profile['atproto'] = [
                'enabled' => $in->bool('atproto.enabled'),
                'handle_example' => $in->string('atproto.handle_example', 253, false),
                'move_away' => $in->bool('atproto.move_away', false) ?? false,
            ];
        }

        $profile['etag'] = $in->string('etag', 128, false);

        if ([] !== $in->errors()) {
            throw new ValidationFailed($in->errors());
        }

        return $profile;
    }

    /**
     * @return list<string>|null
     */
    private function languages(InputReader $in): ?array
    {
        $languages = $in->stringList('audience.languages', 10, 35);
        if (null === $languages) {
            return null;
        }
        $out = [];
        foreach ($languages as $tag) {
            // BCP 47, canonical case: language lowercase, script title case, region uppercase.
            if (1 !== preg_match('/\A([a-zA-Z]{2,3})(?:-([a-zA-Z]{4}))?(?:-([a-zA-Z]{2}|\d{3}))?\z/', $tag, $m)) {
                $in->error('audience.languages', \sprintf('"%s" is not a BCP 47 language tag', $tag));

                return null;
            }
            $canonical = strtolower($m[1]);
            if (!empty($m[2])) {
                $canonical .= '-'.ucfirst(strtolower($m[2]));
            }
            if (!empty($m[3])) {
                $canonical .= '-'.strtoupper($m[3]);
            }
            $out[$canonical] = true;
        }

        return array_keys($out);
    }

    private function topic(InputReader $in): ?string
    {
        $topic = $in->string('audience.topic', 40);
        if (null === $topic) {
            return null;
        }
        $topic = mb_strtolower($topic);
        if (1 !== preg_match('/\A[\p{Ll}\p{Lo}\p{N}]+(?:[ -][\p{Ll}\p{Lo}\p{N}]+)*\z/u', $topic)) {
            $in->error('audience.topic', 'must be "general" or a short topic name');

            return null;
        }

        return $topic;
    }

    private function country(InputReader $in, string $path): ?string
    {
        $value = $in->string($path, 2, true, 2);
        if (null === $value) {
            return null;
        }
        $value = strtoupper($value);
        if (!Countries::exists($value)) {
            $in->error($path, 'must be an ISO 3166-1 alpha-2 country code');

            return null;
        }

        return $value;
    }

    private function yearMonth(InputReader $in, string $path): ?string
    {
        $value = $in->string($path, 7, false, 7);
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match('/\A(20\d\d)-(0[1-9]|1[0-2])\z/', $value)) {
            $in->error($path, 'must look like YYYY-MM');

            return null;
        }

        return $value;
    }
}
