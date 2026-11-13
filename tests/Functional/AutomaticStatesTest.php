<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Check\Checker;
use App\Enum\PauseKind;
use App\Enum\ServerState;
use App\Tests\Support\FunctionalTestCase;

/**
 * The machine rules of concept 6.4 and 13.6: stale, paused, federation, version, failed checks,
 * and the automatic return to "listed".
 */
final class AutomaticStatesTest extends FunctionalTestCase
{
    public function testStaleThenPausedThenBack(): void
    {
        $this->installKeySet();
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $this->tick();

        // 3 hours without a heartbeat: hidden from signup but still listed.
        $this->clock->sleep(4 * 3600);
        $this->tick();
        $entry = $this->publishedList('servers')['entries'][0];
        self::assertSame('unavailable', $entry['signup']['state']);
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state);

        // 7 days: paused.
        $this->clock->sleep(7 * 86400);
        $this->tick();
        $server = $this->server('social.example.org');
        self::assertSame(ServerState::Paused, $server->state);
        self::assertSame(PauseKind::Stale, $server->pauseKind);
        self::assertSame([], $this->publishedList('servers')['entries']);

        // A heartbeat brings it back automatically.
        $this->heartbeat($fake);
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state);
    }

    public function testFederationFailurePausesAfter24Hours(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $hb = \App\Tests\Support\Fixtures::heartbeat('social.example.org', $this->clock->now());
        for ($i = 0; $i < 26; ++$i) {
            $this->clock->sleep(3600);
            $hb = \App\Tests\Support\Fixtures::heartbeat('social.example.org', $this->clock->now());
            $hb['health']['federation_ok'] = false;
            $this->api('PUT', '/api/v1/servers/social.example.org/heartbeat', $hb, $fake);
        }
        $server = $this->server('social.example.org');
        self::assertSame(PauseKind::Federation, $server->pauseKind);

        $this->clock->sleep(3600);
        $this->heartbeat($fake);
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state);
    }

    public function testRepeatedTlsFailurePausesAndRecovers(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $checker = static::getContainer()->get(Checker::class);

        $fake->tlsOk = false;
        $checker->run($this->server('social.example.org'));
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state, 'one failure is tolerated');
        $this->clock->sleep(3600);
        $checker->run($this->server('social.example.org'));
        self::assertSame(PauseKind::Checks, $this->server('social.example.org')->pauseKind);

        $fake->tlsOk = true;
        $this->clock->sleep(3600);
        $this->heartbeat($fake);
        $checker->run($this->server('social.example.org'));
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state);
    }

    public function testOutdatedVersionGetsAGracePeriod(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        for ($day = 0; $day < 32; ++$day) {
            $this->clock->sleep(86400);
            $hb = \App\Tests\Support\Fixtures::heartbeat('social.example.org', $this->clock->now());
            $hb['software']['version'] = '0.26.0';
            $this->api('PUT', '/api/v1/servers/social.example.org/heartbeat', $hb, $fake);
            if ($day < 29) {
                self::assertSame(ServerState::Listed, $this->server('social.example.org')->state, "day {$day}");
            }
        }
        self::assertSame(PauseKind::Version, $this->server('social.example.org')->pauseKind);
    }

    public function testCuratorPauseDoesNotLiftItself(): void
    {
        $this->installKeySet();
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        static::getContainer()->get(\App\Curation\CurationService::class)->pause($this->curator('alice@nextcloud.test'), $this->server('social.example.org'), 'Data breach reported.');
        $this->clock->sleep(3600);
        $this->heartbeat($fake);
        $this->tick();
        self::assertSame(PauseKind::Curator, $this->server('social.example.org')->pauseKind);
        $comments = $this->api('GET', '/api/v1/servers/social.example.org/status', null, $fake)['comments'];
        self::assertStringContainsString('Data breach reported.', $comments[0]['body']);
    }
}
