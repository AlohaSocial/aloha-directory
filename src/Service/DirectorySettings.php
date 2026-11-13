<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Operational settings of the directory, from config.php. None of the thresholds are part of the
 * list format; they only decide how facts turn into published states and buckets.
 */
final readonly class DirectorySettings
{
    /**
     * Defaults for the "thresholds" setting of config.php (documented in config.sample.php).
     */
    public const DEFAULT_THRESHOLDS = [
        // Capacity buckets (concept 13.3)
        'capacity_plenty_min' => 100,
        'capacity_some_min' => 20,
        'approval_long_min' => 20,
        // Heartbeats and automatic states (concept 6.4, 13.6)
        'heartbeat_min_interval_seconds' => 300,
        'stale_after_hours' => 3,
        'pause_after_stale_days' => 7,
        'pause_after_federation_failing_hours' => 24,
        'min_version_grace_days' => 30,
        'check_failures_before_pause' => 2,
        // Curation (concept 7.4, 7.5, 12.4)
        'review_interval_months' => 6,
        'review_due_warning_days' => 14,
        'featured_min_listed_days' => 90,
        'featured_min_availability_percent' => 98,
        'reapply_after_delisting_months' => 6,
        // Lists (concept 8.2, 13.2)
        'list_validity_days' => 30,
        'resign_after_hours' => 24,
        // Change guard: a build that adds or removes more than this share of the entries (and more
        // than the minimum) is held until a curator releases it.
        'list_change_guard_percent' => 10,
        'list_change_guard_min' => 3,
        // Fairness (concept 9.2, goal G3): within a language, a featured server that took more than
        // tolerance% of its fair share of new accounts in 7 days gets a balance factor below 1.
        'fairness_min_new_accounts' => 50,
        'fairness_tolerance_percent' => 150,
        'fairness_min_balance_percent' => 25,
        'fairness_alarm_top_share_percent' => 50,
        // Retention (concept 8.4, 11.2)
        'heartbeat_retention_days' => 90,
        'admin_contact_retention_days' => 365,
        'reporter_contact_retention_days' => 90,
        'list_build_retention_days' => 3650,
        // Monitoring alarms (concept 13.8)
        'alarm_min_free_places' => 1000,
        'alarm_list_age_hours' => 26,
        'alarm_change_pending_minutes' => 10,
        // Rate limits for anonymous endpoints, per IP and hour
        'enrol_per_ip_hour' => 10,
        'reports_per_ip_hour' => 5,
        // Curator console
        'session_idle_minutes' => 30,
        'session_absolute_hours' => 8,
    ];

    public string $publicUrl;
    public string $authority;

    /** @var array<string, int|float> */
    private array $thresholds;

    /** @var list<string> */
    public array $softwareNames;

    /**
     * @param string $softwareNames comma-separated (config.php: software_names)
     * @param string $thresholds    JSON object of overrides (config.php: thresholds)
     */
    public function __construct(
        #[Autowire('%env(DIRECTORY_PUBLIC_URL)%')]
        string $publicUrl,
        #[Autowire('%env(string:DIRECTORY_SOFTWARE_NAMES)%')]
        string $softwareNames,
        #[Autowire('%env(DIRECTORY_MIN_SERVER_VERSION)%')]
        public string $minServerVersion,
        #[Autowire('%env(resolve:DIRECTORY_OUTPUT_DIR)%')]
        public string $outputDir,
        #[Autowire('%env(default::ATPROTO_APPVIEW_URL)%')]
        public ?string $atprotoAppViewUrl,
        #[Autowire('%env(string:default::DIRECTORY_THRESHOLDS)%')]
        ?string $thresholds,
    ) {
        $parts = parse_url($publicUrl);
        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException('DIRECTORY_PUBLIC_URL must be an absolute URL.');
        }
        $this->publicUrl = rtrim($publicUrl, '/');
        $this->authority = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $overrides = null === $thresholds || '' === $thresholds ? [] : json_decode($thresholds, true, 4, \JSON_THROW_ON_ERROR);
        if (!\is_array($overrides)) {
            throw new \InvalidArgumentException('The thresholds in config.php must be an array.');
        }
        $unknown = array_diff(array_keys($overrides), array_keys(self::DEFAULT_THRESHOLDS));
        if ([] !== $unknown) {
            throw new \InvalidArgumentException('Unknown threshold(s) in config.php: '.implode(', ', $unknown));
        }
        foreach ($overrides as $name => $value) {
            if (!\is_int($value) && !\is_float($value)) {
                throw new \InvalidArgumentException(\sprintf('Threshold "%s" must be a number.', $name));
            }
        }
        $this->thresholds = [...self::DEFAULT_THRESHOLDS, ...$overrides];
        $this->softwareNames = array_values(array_filter(array_map(static fn (string $n): string => strtolower(trim($n)), explode(',', $softwareNames))));
    }

    public function int(string $name): int
    {
        return (int) $this->value($name);
    }

    public function float(string $name): float
    {
        return (float) $this->value($name);
    }

    private function value(string $name): int|float
    {
        if (!\array_key_exists($name, $this->thresholds)) {
            throw new \LogicException(\sprintf('Unknown directory setting "%s".', $name));
        }

        return $this->thresholds[$name];
    }
}
