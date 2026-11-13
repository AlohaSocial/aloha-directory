<?php

/**
 * Aloha Directory — central configuration.
 *
 * Copy this file to config.php and adjust it. config.php is the only place where the directory
 * is configured; it is read before the application starts. Keep it out of version control and
 * readable only by the web server user (chmod 640): it contains secrets.
 *
 * An environment variable of the same meaning (shown in brackets) overrides a value here, which is
 * how the Docker setup injects secrets. Unknown keys are rejected, so typos fail loudly.
 */

return [
    // --- Application ------------------------------------------------------------------------

    // "prod" in production, "dev" for local development. [APP_ENV]
    'app_env' => 'prod',

    // Never enable in production: shows stack traces and the profiler. [APP_DEBUG]
    'debug' => false,

    // Random secret for CSRF tokens and signed URLs: php -r 'echo bin2hex(random_bytes(32)), "\n";' [APP_SECRET]
    'app_secret' => 'CHANGE-ME',

    // The public address of the directory (concept D15). Its host is also the "@authority" every
    // server must sign (RFC 9421), so it must match exactly what servers call. [DIRECTORY_PUBLIC_URL]
    'public_url' => 'https://directory.aloha.social',

    // Reverse proxies whose X-Forwarded-* headers are trusted, e.g. ['10.0.0.0/8']. [TRUSTED_PROXIES]
    'trusted_proxies' => [],

    // --- Storage ------------------------------------------------------------------------------

    // MariaDB 10.11+ (concept D16). [DATABASE_URL]
    'database_url' => 'mysql://directory:CHANGE-ME@127.0.0.1:3306/directory?serverVersion=10.11.0-MariaDB&charset=utf8mb4',

    // Locks for the cron jobs. Use the database when more than one host runs cron jobs:
    // the same value as database_url. "flock" is fine on a single host. [LOCK_DSN]
    'lock_dsn' => 'flock',

    // Where the static lists, media and public website are written (concept 13.2, 13.5). The web
    // server serves this directory directly; PHP is only asked when a file does not exist. [DIRECTORY_OUTPUT_DIR]
    'output_dir' => __DIR__.'/public',

    // --- Keys (concept 8.3) -------------------------------------------------------------------

    // Keys the hashes of curator recovery codes. 32 random bytes, base64url: bin/console app:secrets:generate-encryption-key
    // Losing it invalidates all recovery codes (curators create new ones under Account). [DIRECTORY_ENCRYPTION_KEY]
    'encryption_key' => 'CHANGE-ME',

    // List signing keys: key id => path of the seed file (bin/console app:keys:generate). During a
    // rotation, configure both keys for 30 days; every list then carries both signatures. [LIST_SIGNING_KEYS]
    'list_signing_keys' => [
        'aloha-list-2026a' => '/etc/aloha-directory/keys/aloha-list-2026a.key',
    ],

    // The pinned root public key(s) that sign keys.json: key id => base64url public key. These are
    // the same keys the apps and the server app pin. [ROOT_PUBLIC_KEYS]
    'root_public_keys' => [
        'aloha-root-2026' => 'CHANGE-ME',
    ],

    // --- Directory rules ------------------------------------------------------------------------

    // Accepted nodeinfo software.name values of the Aloha app (concept 6.1, D2). [DIRECTORY_SOFTWARE_NAMES]
    'software_names' => ['aloha'],

    // Servers below this version are paused after the grace period (concept 13.6). [DIRECTORY_MIN_SERVER_VERSION]
    'min_server_version' => '0.27.0',

    // The Bluesky AppView named in search.json (concept 15.3); null to leave it out. [ATPROTO_APPVIEW_URL]
    'atproto_appview_url' => 'https://public.api.bsky.app',

    // Staging only: allows bin/console app:loadtest:seed to add fake servers (docs/loadtest.md).
    // Never enable it on the production directory. [DIRECTORY_LOADTEST_ENABLED]
    'loadtest_enabled' => false,

    // Overrides for the thresholds. Leave out what you do not change; the defaults are shown.
    // Unknown names are rejected. [DIRECTORY_THRESHOLDS, as JSON]
    'thresholds' => [
        // Capacity buckets in servers.json (concept 13.3)
        // 'capacity_plenty_min' => 100,          // "plenty" from this many free accounts
        // 'capacity_some_min' => 20,             // "some" from this many, "few" below
        // 'approval_long_min' => 20,             // approval_wait "long" from this many waiting requests

        // Heartbeats and automatic states (concept 6.4, 13.6)
        // 'heartbeat_min_interval_seconds' => 300,
        // 'stale_after_hours' => 3,              // hidden from signup
        // 'pause_after_stale_days' => 7,         // paused, curators told
        // 'pause_after_federation_failing_hours' => 24,
        // 'min_version_grace_days' => 30,
        // 'check_failures_before_pause' => 2,    // consecutive hourly failures of a critical check

        // Curation (concept 7.4, 7.5, 12.4)
        // 'review_interval_months' => 6,
        // 'review_due_warning_days' => 14,
        // 'featured_min_listed_days' => 90,
        // 'featured_min_availability_percent' => 98,
        // 'reapply_after_delisting_months' => 6,

        // Lists (concept 8.2, 13.2)
        // 'list_validity_days' => 30,
        // 'resign_after_hours' => 24,
        // A build adding or removing more than this share of the entries (and more than the
        // minimum number) is held until a curator releases it in the console.
        // 'list_change_guard_percent' => 10,
        // 'list_change_guard_min' => 3,

        // Fairness (concept 9.2): spread newcomers across servers of the same language
        // 'fairness_min_new_accounts' => 50,        // per language, before balancing starts
        // 'fairness_tolerance_percent' => 150,      // share of the fair share before the factor drops
        // 'fairness_min_balance_percent' => 25,
        // 'fairness_alarm_top_share_percent' => 50, // alarm when one server takes this share overall

        // Retention (concept 8.4, 11.2)
        // 'heartbeat_retention_days' => 90,
        // 'admin_contact_retention_days' => 365,  // after withdrawal or delisting
        // 'reporter_contact_retention_days' => 90, // after a complaint is closed
        // 'list_build_retention_days' => 3650,

        // Monitoring alarms (concept 13.8)
        // 'alarm_min_free_places' => 1000,
        // 'alarm_list_age_hours' => 26,
        // 'alarm_change_pending_minutes' => 10,

        // Anonymous endpoints, per IP address and hour
        // 'enrol_per_ip_hour' => 10,
        // 'reports_per_ip_hour' => 5,

        // Curator console
        // 'session_idle_minutes' => 30,
        // 'session_absolute_hours' => 8,
    ],

    // --- Mail and mirror ----------------------------------------------------------------------

    // Symfony Mailer DSN, e.g. smtp://user:pass@mail.example.org:587 ; "null://null" disables mail. [MAILER_DSN]
    'mailer_dsn' => 'null://null',

    // Sender of notifications to curators and server admins. [DIRECTORY_MAIL_FROM]
    'mail_from' => 'Aloha Directory <directory@aloha.social>',

    // A clone of alohasocial/directory-lists with push access; every published list is committed
    // and pushed (concept 8.4). null disables the mirror. [MIRROR_REPO_DIR]
    'mirror_repo_dir' => null,

    // --- Monitoring -----------------------------------------------------------------------------

    // Bearer token for GET /metrics (Prometheus format); null disables the endpoint. [METRICS_TOKEN]
    'metrics_token' => null,
];
