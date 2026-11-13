<?php

// Configuration used by the test suite only (see tests/bootstrap.php).

return [
    'app_env' => 'test',
    'debug' => true,
    'app_secret' => 'test-secret-not-for-production-0123456789',
    'public_url' => 'https://directory.test-aloha.social',
    'trusted_proxies' => [],
    'database_url' => 'sqlite:///'.dirname(__DIR__).'/var/test.db',
    'lock_dsn' => 'flock',
    'output_dir' => dirname(__DIR__).'/var/test-output',
    'encryption_key' => 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8',
    'list_signing_keys' => ['test-list-a' => dirname(__DIR__).'/var/test-keys/test-list-a.key'],
    'root_public_keys' => ['test-root' => 'gTl3Dqh9F19Wo1Rmw0x-zMuNipG07jeiXfYPW4_Js5Q'],
    'software_names' => ['aloha'],
    'min_server_version' => '0.27.0',
    'atproto_appview_url' => 'https://public.api.bsky.app',
    'loadtest_enabled' => true,
    'thresholds' => [],
    'mailer_dsn' => 'null://null',
    'mail_from' => 'Aloha Directory <directory@test-aloha.social>',
    'mirror_repo_dir' => null,
    'metrics_token' => 'test-metrics-token',
];
