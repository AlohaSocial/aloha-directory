<?php

declare(strict_types=1);

namespace App\Curation;

/**
 * The Aloha Server Covenant (concept 12.1) and the review checklists (concept 7.3, 7.5).
 * Rule keys are what the transparency log records as "rule broken".
 */
final class Covenant
{
    public const RULES = [
        'active_moderation' => ['Active moderation', 'Active moderation against racism, sexism, homophobia and transphobia, and against harassment and illegal content.'],
        'backups' => ['Daily backups', 'Daily backups, and a tested restore process.'],
        'emergency_access' => ['Emergency access', 'At least two people with emergency access to the server\'s infrastructure.'],
        'shutdown_notice' => ['Shutdown notice', 'At least 3 months\' notice before shutting down, while keeping account export and move working.'],
        'data_portability' => ['Data portability', 'Account export and account move are never switched off.'],
        'no_tracking' => ['No tracking', 'No tracking or advertising that uses users\' personal data.'],
        'honest_data' => ['Honest data', 'The profile and heartbeat are true and up to date.'],
        'search_provider' => ['Search providers', 'Search providers follow the opt-in index rules and keep query logs for at most 7 days.'],
    ];

    /** Additional reasons a delisting can cite. */
    public const OTHER_REASONS = [
        'listing_criteria' => 'No longer meets the listing criteria',
        'unreachable_operator' => 'The operator cannot be reached',
        'shutdown' => 'The server shut down',
    ];

    public const INITIAL_CHECKLIST = [
        'operator' => ['Operator', 'The operator is a real, reachable person or organisation, and the funding story is believable for the promised commitment period.'],
        'moderation' => ['Moderation', 'The rules are clear and in line with the covenant; there are enough moderators (about one per 1,000 monthly active users); a test report to the abuse contact was answered within the promised time.'],
        'legal' => ['Legal', 'Terms and privacy policy exist in the primary languages, cover deletion and export, and the jurisdiction is stated.'],
        'experience' => ['Experience', 'A test signup worked, approval was reasonably quick, and federation, media and search work.'],
        'mix' => ['Mix', 'The server adds to the balance of languages, regions and topics.'],
    ];

    public const REREVIEW_CHECKLIST = [
        'test_report' => ['Test report', 'A new test report to the abuse contact was answered within the promised time.'],
        'test_signup' => ['Test signup', 'A test signup worked.'],
        'complaints' => ['Complaints', 'The complaints since the last review were looked at and none is unresolved.'],
    ];

    /**
     * @return array<string, string>
     */
    public static function delistingReasons(): array
    {
        $reasons = [];
        foreach (self::RULES as $key => [$title]) {
            $reasons[$key] = 'Covenant: '.$title;
        }

        return $reasons + self::OTHER_REASONS;
    }
}
