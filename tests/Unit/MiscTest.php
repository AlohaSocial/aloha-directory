<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\ConfigLoader;
use App\Entity\Server;
use App\Service\Buckets;
use App\Service\DirectorySettings;
use App\Service\VersionComparator;
use PHPUnit\Framework\TestCase;

final class MiscTest extends TestCase
{
    public function testVersionComparator(): void
    {
        self::assertTrue(VersionComparator::atLeast('0.27.0', '0.27.0'));
        self::assertTrue(VersionComparator::atLeast('0.27.1', '0.27.0'));
        self::assertTrue(VersionComparator::atLeast('1.0', '0.27.0'));
        self::assertFalse(VersionComparator::atLeast('0.26.140', '0.27.0'));
        self::assertFalse(VersionComparator::atLeast('0.27.0-beta.1', '0.27.0'));
        self::assertFalse(VersionComparator::atLeast('garbage', '0.0.1'));
    }

    public function testBuckets(): void
    {
        $buckets = new Buckets($this->settings());
        $server = new Server('a.example.org', new \DateTimeImmutable());
        self::assertSame('full', $buckets->capacity($server), 'no heartbeat yet');

        $server->signupOpen = true;
        $server->signupMode = 'approval';
        foreach ([150 => 'plenty', 100 => 'plenty', 99 => 'some', 20 => 'some', 19 => 'few', 1 => 'few', 0 => 'full'] as $free => $bucket) {
            $server->availableAccounts = $free;
            self::assertSame($bucket, $buckets->capacity($server), (string) $free);
        }
        $server->availableAccounts = 500;
        $server->signupOpen = false;
        self::assertSame('full', $buckets->capacity($server));

        foreach ([0 => 'none', 5 => 'short', 19 => 'short', 20 => 'long'] as $backlog => $bucket) {
            $server->approvalBacklog = $backlog;
            self::assertSame($bucket, $buckets->approvalWait($server));
        }
        $server->signupMode = 'open';
        self::assertSame('none', $buckets->approvalWait($server));
    }

    public function testSettingsRejectUnknownThresholds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DirectorySettings('https://d.example.org', 'aloha', '0.27.0', '/tmp', null, '{"capacity_plenty":5}');
    }

    public function testSettingsOverrides(): void
    {
        $settings = new DirectorySettings('https://d.example.org:8443/', 'Aloha, aloha-dev', '0.27.0', '/tmp', null, '{"capacity_plenty_min":50}');
        self::assertSame(50, $settings->int('capacity_plenty_min'));
        self::assertSame(20, $settings->int('capacity_some_min'));
        self::assertSame('d.example.org:8443', $settings->authority);
        self::assertSame(['aloha', 'aloha-dev'], $settings->softwareNames);
    }

    public function testConfigLoaderMapsSettings(): void
    {
        $env = ConfigLoader::toEnvironment([
            'public_url' => 'https://directory.aloha.social',
            'debug' => false,
            'list_signing_keys' => ['a' => '/k/a.key', 'b' => '/k/b.key'],
            'software_names' => ['aloha'],
            'thresholds' => ['stale_after_hours' => 4],
            'mirror_repo_dir' => null,
        ]);
        self::assertSame('https://directory.aloha.social', $env['DIRECTORY_PUBLIC_URL']);
        self::assertSame('https://directory.aloha.social', $env['DEFAULT_URI']);
        self::assertSame('0', $env['APP_DEBUG']);
        self::assertSame('a=/k/a.key,b=/k/b.key', $env['LIST_SIGNING_KEYS']);
        self::assertSame('{"stale_after_hours":4}', $env['DIRECTORY_THRESHOLDS']);
        self::assertSame('', $env['MIRROR_REPO_DIR']);
    }

    public function testConfigLoaderRejectsTypos(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('databse_url');
        ConfigLoader::toEnvironment(['databse_url' => 'x']);
    }

    public function testSampleConfigIsComplete(): void
    {
        $sample = require \dirname(__DIR__, 2).'/config.sample.php';
        $env = ConfigLoader::toEnvironment($sample);
        self::assertArrayHasKey('DATABASE_URL', $env);
        self::assertSame([], $sample['thresholds'], 'all thresholds in the sample are commented defaults');
        foreach (array_keys(DirectorySettings::DEFAULT_THRESHOLDS) as $name) {
            self::assertStringContainsString("'{$name}'", (string) file_get_contents(\dirname(__DIR__, 2).'/config.sample.php'), "{$name} is documented");
        }
    }

    private function settings(): DirectorySettings
    {
        return new DirectorySettings('https://d.example.org', 'aloha', '0.27.0', '/tmp', null, null);
    }
}
