<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Loads the central config.php into the process environment before Symfony boots, so that every
 * setting of the directory lives in one file. Real environment variables win over config.php,
 * which keeps container deployments (secrets injected as environment variables) possible.
 *
 * The file is chosen by ALOHA_DIRECTORY_CONFIG, or defaults to <project>/config.php.
 */
final class ConfigLoader
{
    /**
     * config.php key => [environment variable(s), encoder]
     *
     * @var array<string, array{0: list<string>, 1: string}>
     */
    private const MAP = [
        'app_env' => [['APP_ENV'], 'string'],
        'debug' => [['APP_DEBUG'], 'bool'],
        'app_secret' => [['APP_SECRET'], 'string'],
        'public_url' => [['DIRECTORY_PUBLIC_URL', 'DEFAULT_URI'], 'string'],
        'database_url' => [['DATABASE_URL'], 'string'],
        'lock_dsn' => [['LOCK_DSN'], 'string'],
        'mailer_dsn' => [['MAILER_DSN'], 'string'],
        'mail_from' => [['DIRECTORY_MAIL_FROM'], 'string'],
        'encryption_key' => [['DIRECTORY_ENCRYPTION_KEY'], 'string'],
        'list_signing_keys' => [['LIST_SIGNING_KEYS'], 'pairs'],
        'root_public_keys' => [['ROOT_PUBLIC_KEYS'], 'pairs'],
        'software_names' => [['DIRECTORY_SOFTWARE_NAMES'], 'csv'],
        'min_server_version' => [['DIRECTORY_MIN_SERVER_VERSION'], 'string'],
        'output_dir' => [['DIRECTORY_OUTPUT_DIR'], 'string'],
        'atproto_appview_url' => [['ATPROTO_APPVIEW_URL'], 'string'],
        'mirror_repo_dir' => [['MIRROR_REPO_DIR'], 'string'],
        'metrics_token' => [['METRICS_TOKEN'], 'string'],
        'trusted_proxies' => [['TRUSTED_PROXIES'], 'csv'],
        'thresholds' => [['DIRECTORY_THRESHOLDS'], 'json'],
        'loadtest_enabled' => [['DIRECTORY_LOADTEST_ENABLED'], 'bool'],
    ];

    public static function load(string $projectDir): void
    {
        // All timestamps are UTC: in the database, in the lists and in the API.
        date_default_timezone_set('UTC');

        $path = $_SERVER['ALOHA_DIRECTORY_CONFIG'] ?? $_ENV['ALOHA_DIRECTORY_CONFIG'] ?? getenv('ALOHA_DIRECTORY_CONFIG') ?: $projectDir.'/config.php';
        if (!is_file($path)) {
            // Building a container image (cache warm-up) runs with environment variables only.
            if (false !== getenv('ALOHA_DIRECTORY_ENV_ONLY')) {
                return;
            }
            throw new \RuntimeException(\sprintf('Configuration file "%s" not found. Copy config.sample.php to config.php and adjust it.', $path));
        }
        $config = (static fn (string $file): mixed => require $file)($path);
        if (!\is_array($config)) {
            throw new \RuntimeException(\sprintf('"%s" must return an array.', $path));
        }

        foreach (self::toEnvironment($config) as $name => $value) {
            if (\array_key_exists($name, $_SERVER) || \array_key_exists($name, $_ENV) || false !== getenv($name)) {
                continue; // a real environment variable overrides config.php
            }
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $_SERVER['SYMFONY_DOTENV_VARS'] ??= '';
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<string, string>
     */
    public static function toEnvironment(array $config): array
    {
        $unknown = array_diff(array_keys($config), array_keys(self::MAP));
        if ([] !== $unknown) {
            throw new \RuntimeException('Unknown setting(s) in config.php: '.implode(', ', $unknown));
        }

        $env = [];
        foreach ($config as $key => $value) {
            [$names, $type] = self::MAP[$key];
            if (null === $value) {
                $encoded = '';
            } else {
                $encoded = match ($type) {
                    'string' => \is_scalar($value) ? (string) $value : throw new \RuntimeException(\sprintf('Setting "%s" must be a string.', $key)),
                    'bool' => \is_bool($value) ? ($value ? '1' : '0') : throw new \RuntimeException(\sprintf('Setting "%s" must be true or false.', $key)),
                    'csv' => \is_array($value) ? implode(',', array_map('strval', $value)) : throw new \RuntimeException(\sprintf('Setting "%s" must be a list.', $key)),
                    'pairs' => \is_array($value) ? self::pairs($key, $value) : throw new \RuntimeException(\sprintf('Setting "%s" must be an array of id => value.', $key)),
                    'json' => \is_array($value) ? json_encode($value, \JSON_THROW_ON_ERROR) : throw new \RuntimeException(\sprintf('Setting "%s" must be an array.', $key)),
                };
            }
            foreach ($names as $name) {
                $env[$name] = $encoded;
            }
        }

        return $env;
    }

    /**
     * @param array<mixed> $value
     */
    private static function pairs(string $key, array $value): string
    {
        $parts = [];
        foreach ($value as $id => $item) {
            if (!\is_string($id) || !\is_string($item) || str_contains($id, '=') || str_contains($id, ',') || str_contains($item, ',')) {
                throw new \RuntimeException(\sprintf('Setting "%s" must map ids to strings without "," or "=".', $key));
            }
            $parts[] = $id.'='.$item;
        }

        return implode(',', $parts);
    }
}
