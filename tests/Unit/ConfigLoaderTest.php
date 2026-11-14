<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\ConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private const TOUCHED = ['ALOHA_DIRECTORY_CONFIG', 'MIRROR_REPO_DIR', 'METRICS_TOKEN', 'APP_SECRET', 'SYMFONY_DOTENV_VARS'];

    private string $dir;
    /** @var array<string, array{server: mixed, env: mixed, getenv: string|false, inServer: bool, inEnv: bool}> */
    private array $saved = [];
    private string|false $envOnly;
    private string $timezone;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/aloha-config-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
        foreach (self::TOUCHED as $name) {
            $this->saved[$name] = [
                'server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null, 'getenv' => getenv($name),
                'inServer' => \array_key_exists($name, $_SERVER), 'inEnv' => \array_key_exists($name, $_ENV),
            ];
        }
        $this->envOnly = getenv('ALOHA_DIRECTORY_ENV_ONLY');
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $s) {
            unset($_SERVER[$name], $_ENV[$name]);
            if ($s['inServer']) {
                $_SERVER[$name] = $s['server'];
            }
            if ($s['inEnv']) {
                $_ENV[$name] = $s['env'];
            }
            false === $s['getenv'] ? putenv($name) : putenv($name.'='.$s['getenv']);
        }
        false === $this->envOnly ? putenv('ALOHA_DIRECTORY_ENV_ONLY') : putenv('ALOHA_DIRECTORY_ENV_ONLY='.$this->envOnly);
        date_default_timezone_set($this->timezone);
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    // --- toEnvironment -------------------------------------------------------------------------

    public function testEncodersForEveryType(): void
    {
        $env = ConfigLoader::toEnvironment([
            'debug' => true,
            'loadtest_enabled' => false,
            'app_secret' => 'abc',
            'mailer_dsn' => 'smtp://user:p%40ss@mail.example.org:587',
            'software_names' => ['aloha', 'aloha-dev'],
            'trusted_proxies' => ['10.0.0.0/8', '192.168.0.1'],
            'root_public_keys' => ['root-2026' => 'gTl3Dqh9F19Wo1Rmw0x-zMuNipG07jeiXfYPW4_Js5Q'],
            'thresholds' => ['capacity_plenty_min' => 50, 'alarm_list_age_hours' => 2],
            'metrics_token' => 12345,
        ]);

        self::assertSame([
            'APP_DEBUG' => '1',
            'DIRECTORY_LOADTEST_ENABLED' => '0',
            'APP_SECRET' => 'abc',
            'MAILER_DSN' => 'smtp://user:p%40ss@mail.example.org:587',
            'DIRECTORY_SOFTWARE_NAMES' => 'aloha,aloha-dev',
            'TRUSTED_PROXIES' => '10.0.0.0/8,192.168.0.1',
            'ROOT_PUBLIC_KEYS' => 'root-2026=gTl3Dqh9F19Wo1Rmw0x-zMuNipG07jeiXfYPW4_Js5Q',
            'DIRECTORY_THRESHOLDS' => '{"capacity_plenty_min":50,"alarm_list_age_hours":2}',
            'METRICS_TOKEN' => '12345',
        ], $env);
    }

    public function testNullAndEmptyValues(): void
    {
        $env = ConfigLoader::toEnvironment(['metrics_token' => null, 'debug' => null, 'trusted_proxies' => [], 'thresholds' => [], 'list_signing_keys' => []]);
        self::assertSame(['METRICS_TOKEN' => '', 'APP_DEBUG' => '', 'TRUSTED_PROXIES' => '', 'DIRECTORY_THRESHOLDS' => '[]', 'LIST_SIGNING_KEYS' => ''], $env);
    }

    public function testEmptyConfigMapsToNothing(): void
    {
        self::assertSame([], ConfigLoader::toEnvironment([]));
    }

    #[DataProvider('wrongTypes')]
    public function testWrongTypesAreRejected(string $key, mixed $value, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        ConfigLoader::toEnvironment([$key => $value]);
    }

    public static function wrongTypes(): iterable
    {
        yield 'string given a list' => ['app_secret', ['a'], '"app_secret" must be a string'];
        yield 'string given an object' => ['public_url', new \stdClass(), '"public_url" must be a string'];
        yield 'bool given a string' => ['debug', 'false', '"debug" must be true or false'];
        yield 'bool given an int' => ['loadtest_enabled', 1, '"loadtest_enabled" must be true or false'];
        yield 'csv given a string' => ['software_names', 'aloha', '"software_names" must be a list'];
        yield 'pairs given a string' => ['list_signing_keys', 'a=/k', '"list_signing_keys" must be an array of id => value'];
        yield 'pairs with a list' => ['list_signing_keys', ['/k/a.key'], '"list_signing_keys" must map ids'];
        yield 'pairs with "=" in the id' => ['root_public_keys', ['a=b' => 'key'], '"root_public_keys" must map ids'];
        yield 'pairs with "," in the id' => ['root_public_keys', ['a,b' => 'key'], '"root_public_keys" must map ids'];
        yield 'pairs with "," in the value' => ['list_signing_keys', ['a' => '/k/a.key,b=/evil'], '"list_signing_keys" must map ids'];
        yield 'pairs with a non-string value' => ['list_signing_keys', ['a' => 5], '"list_signing_keys" must map ids'];
        yield 'json given a string' => ['thresholds', '{"a":1}', '"thresholds" must be an array'];
    }

    public function testEveryUnknownKeyIsNamed(): void
    {
        try {
            ConfigLoader::toEnvironment(['public_url' => 'https://d.example.org', 'Debug' => true, 'mirror_dir' => '/x']);
            self::fail('Unknown keys were accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('Unknown setting(s) in config.php: Debug, mirror_dir', $e->getMessage());
        }
    }

    public function testPublicUrlFeedsBothVariables(): void
    {
        $env = ConfigLoader::toEnvironment(['public_url' => 'https://d.example.org']);
        self::assertSame(['DIRECTORY_PUBLIC_URL' => 'https://d.example.org', 'DEFAULT_URI' => 'https://d.example.org'], $env);
    }

    // --- load ----------------------------------------------------------------------------------

    public function testLoadSetsUnsetVariablesAndKeepsRealEnvironment(): void
    {
        unset($_SERVER['MIRROR_REPO_DIR'], $_ENV['MIRROR_REPO_DIR'], $_SERVER['METRICS_TOKEN'], $_ENV['METRICS_TOKEN']);
        putenv('MIRROR_REPO_DIR');
        putenv('METRICS_TOKEN=from-real-env');
        $_SERVER['APP_SECRET'] = 'from-server';

        $_SERVER['ALOHA_DIRECTORY_CONFIG'] = $this->config("['mirror_repo_dir' => '/srv/mirror', 'metrics_token' => 'from-file', 'app_secret' => 'from-file']");
        date_default_timezone_set('Europe/Berlin');
        ConfigLoader::load('/nonexistent');

        self::assertSame('/srv/mirror', $_SERVER['MIRROR_REPO_DIR']);
        self::assertSame('/srv/mirror', $_ENV['MIRROR_REPO_DIR']);
        self::assertArrayNotHasKey('METRICS_TOKEN', $_SERVER, 'a real environment variable (getenv) wins');
        self::assertSame('from-server', $_SERVER['APP_SECRET'], 'a server variable wins');
        self::assertSame('UTC', date_default_timezone_get(), 'all timestamps are UTC');
        self::assertArrayHasKey('SYMFONY_DOTENV_VARS', $_SERVER);
    }

    public function testLoadFallsBackToTheProjectConfig(): void
    {
        unset($_SERVER['ALOHA_DIRECTORY_CONFIG'], $_ENV['ALOHA_DIRECTORY_CONFIG'], $_SERVER['MIRROR_REPO_DIR'], $_ENV['MIRROR_REPO_DIR']);
        putenv('ALOHA_DIRECTORY_CONFIG');
        putenv('MIRROR_REPO_DIR');
        file_put_contents($this->dir.'/config.php', "<?php return ['mirror_repo_dir' => '/from/project'];");

        ConfigLoader::load($this->dir);
        self::assertSame('/from/project', $_SERVER['MIRROR_REPO_DIR']);
    }

    public function testLoadFailsWithoutAConfigFile(): void
    {
        putenv('ALOHA_DIRECTORY_ENV_ONLY');
        $_SERVER['ALOHA_DIRECTORY_CONFIG'] = $this->dir.'/missing.php';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found. Copy config.sample.php');
        ConfigLoader::load('/nonexistent');
    }

    public function testEnvironmentOnlyModeToleratesAMissingFile(): void
    {
        putenv('ALOHA_DIRECTORY_ENV_ONLY=1');
        $_SERVER['ALOHA_DIRECTORY_CONFIG'] = $this->dir.'/missing.php';
        $before = $_SERVER;

        ConfigLoader::load('/nonexistent');
        self::assertSame($before, $_SERVER);
    }

    public function testLoadRejectsAFileThatDoesNotReturnAnArray(): void
    {
        $_SERVER['ALOHA_DIRECTORY_CONFIG'] = $this->config("'oops'");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an array');
        ConfigLoader::load('/nonexistent');
    }

    public function testLoadRejectsTypos(): void
    {
        $_SERVER['ALOHA_DIRECTORY_CONFIG'] = $this->config("['mirror_repo' => '/x']");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown setting(s) in config.php: mirror_repo');
        ConfigLoader::load('/nonexistent');
    }

    private function config(string $returnExpression): string
    {
        $path = $this->dir.'/config-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, "<?php\nreturn {$returnExpression};\n");

        return $path;
    }
}
