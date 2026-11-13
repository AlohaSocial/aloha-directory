<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Check\Checker;
use App\Service\Maintenance;
use App\Tests\Support\FunctionalTestCase;

final class ServerPageTest extends FunctionalTestCase
{
    public function testServerPageShowsUptimeAndCheckHistory(): void
    {
        $this->installKeySet();
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        for ($i = 0; $i < 30; ++$i) {
            $this->clock->sleep(3600);
            $this->heartbeat($fake);
        }
        $fake->tlsOk = false;
        static::getContainer()->get(Checker::class)->run($this->server('social.example.org'));
        $fake->tlsOk = true;
        $this->clock->sleep(3600);
        $this->heartbeat($fake);
        static::getContainer()->get(Checker::class)->run($this->server('social.example.org'));

        $this->tick();
        static::getContainer()->get(Maintenance::class)->run();
        $file = static::getContainer()->getParameter('kernel.project_dir').'/var/test-output/servers/social.example.org/index.html';
        self::assertFileExists($file);
        $html = (string) file_get_contents($file);

        self::assertStringContainsString('id="h-reliability"', $html);
        self::assertSame(90, substr_count($html, '<rect '), 'one bar per day');
        self::assertMatchesRegularExpression('/class="up-good"[^>]*><title>\d{4}-\d{2}-\d{2}: 100%<\/title>/', $html);
        self::assertStringContainsString('HTTPS with a valid certificate', $html);
        self::assertStringContainsString('day-mixed', $html, 'TLS failed once and passed once today');
        self::assertStringContainsString('<span class="check-pass">Passing</span>', $html);
        self::assertStringContainsString('Listed since', $html);
        self::assertStringNotContainsString('style=', $html, 'no inline styles (CSP)');
        self::assertStringNotContainsString('users_total', $html);
    }
}
