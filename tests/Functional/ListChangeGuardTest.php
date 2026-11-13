<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Curation\CurationService;
use App\Entity\ListState;
use App\Tests\Support\FunctionalTestCase;

final class ListChangeGuardTest extends FunctionalTestCase
{
    public function testMassAdditionIsHeldUntilReleased(): void
    {
        $this->installKeySet();
        $this->listServer($this->enrolServer('s0.example.org'));
        $this->tick();
        self::assertCount(1, $this->publishedList('servers')['entries']);

        // Four servers at once: more than the minimum of three.
        for ($i = 1; $i <= 4; ++$i) {
            $this->listServer($this->enrolServer("s{$i}.example.org"));
        }
        $this->tick();
        self::assertCount(1, $this->publishedList('servers')['entries'], 'the build is held');
        $state = $this->em->find(ListState::class, 'servers');
        self::assertNotNull($state?->heldSince);
        self::assertCount(4, $state->heldSummary['added'] ?? []);

        // Held builds stay held on the next minute.
        $this->tick();
        self::assertCount(1, $this->publishedList('servers')['entries']);

        $state->releasedHash = $state->heldHash;
        $this->em->flush();
        $result = $this->tick();
        self::assertCount(5, $this->publishedList('servers')['entries']);
        self::assertSame(['servers#'.$this->publishedList('servers')['serial']], array_values(array_filter($result['builds'], static fn (string $b): bool => str_starts_with($b, 'servers'))));
        $state = $this->em->find(ListState::class, 'servers');
        $this->em->refresh($state);
        self::assertNull($state->heldSince);
        self::assertNull($state->releasedHash);
    }

    public function testMassAutomaticPauseIsHeldResignedAndClearsByItself(): void
    {
        $this->installKeySet();
        $fakes = [];
        for ($i = 0; $i < 5; ++$i) {
            $fakes[$i] = $this->enrolServer("s{$i}.example.org");
            $this->listServer($fakes[$i]);
        }
        $this->tick(true);
        $serial = $this->publishedList('servers')['serial'];

        // Four of five servers stop reporting at once (as if the directory lost its network): they
        // are paused automatically, but they stay in the published list.
        $this->clock->sleep(8 * 86400);
        $this->heartbeat($fakes[4]);
        $this->tick();
        self::assertSame(\App\Enum\PauseKind::Stale, $this->server('s0.example.org')->pauseKind);
        self::assertCount(5, $this->publishedList('servers')['entries']);
        $state = $this->em->find(ListState::class, 'servers');
        self::assertCount(4, $state?->heldSummary['removed'] ?? []);

        // A day later the list is re-signed, still with all five, so it does not expire.
        $this->clock->sleep(25 * 3600);
        $this->heartbeat($fakes[4]);
        $this->tick();
        $list = $this->publishedList('servers');
        self::assertGreaterThan($serial, $list['serial']);
        self::assertCount(5, $list['entries']);

        // The servers report again and resume by themselves: the hold clears without a release.
        foreach ($fakes as $i => $fake) {
            if ($i < 4) {
                $this->heartbeat($fake);
            }
        }
        $this->tick();
        $state = $this->em->find(ListState::class, 'servers');
        $this->em->refresh($state);
        self::assertNull($state->heldSince);
        self::assertCount(5, $this->publishedList('servers')['entries']);
    }

    public function testCuratorPausesAreNeverHeld(): void
    {
        $this->installKeySet();
        for ($i = 0; $i < 5; ++$i) {
            $this->listServer($this->enrolServer("s{$i}.example.org"));
        }
        $this->tick(true);
        $curation = static::getContainer()->get(CurationService::class);
        for ($i = 0; $i < 4; ++$i) {
            $curation->pause($this->curator('alice@nextcloud.test'), $this->server("s{$i}.example.org"), 'Hostile operator.');
        }
        $this->tick();
        self::assertSame(['s4.example.org'], array_column($this->publishedList('servers')['entries'], 'domain'));
        self::assertNull($this->em->find(ListState::class, 'servers')?->heldSince);
    }

    public function testCuratorPausesGoOutWhileAdditionsAreHeld(): void
    {
        $this->installKeySet();
        $this->listServer($this->enrolServer('s0.example.org'));
        $this->listServer($this->enrolServer('s1.example.org'));
        $this->tick(true);
        for ($i = 2; $i <= 5; ++$i) {
            $this->listServer($this->enrolServer("s{$i}.example.org"));
        }
        $this->tick();
        self::assertSame(['s0.example.org', 's1.example.org'], array_column($this->publishedList('servers')['entries'], 'domain'));

        static::getContainer()->get(CurationService::class)->pause($this->curator('alice@nextcloud.test'), $this->server('s0.example.org'), 'Hostile.');
        $this->tick();
        self::assertSame(['s1.example.org'], array_column($this->publishedList('servers')['entries'], 'domain'), 'the pause is published during the hold');
        $state = $this->em->find(ListState::class, 'servers');
        $this->em->refresh($state);
        self::assertNotNull($state->heldSince);
        self::assertCount(4, $state->heldSummary['added']);
    }

    public function testSmallChangesPassTheGuard(): void
    {
        $this->installKeySet();
        $this->listServer($this->enrolServer('s0.example.org'));
        $this->tick();
        for ($i = 1; $i <= 3; ++$i) {
            $this->listServer($this->enrolServer("s{$i}.example.org"));
        }
        $this->tick();
        self::assertCount(4, $this->publishedList('servers')['entries']);
    }

    public function testCuratorReleasesAHeldListInTheConsole(): void
    {
        $this->installKeySet();
        $this->listServer($this->enrolServer('s0.example.org'));
        $this->tick();
        for ($i = 1; $i <= 4; ++$i) {
            $this->listServer($this->enrolServer("s{$i}.example.org"));
        }
        $this->tick();
        self::assertCount(1, $this->publishedList('servers')['entries']);

        $this->consoleLogin('alice@nextcloud.test');
        $crawler = $this->client->request('GET', 'https://directory.test-aloha.social/console/lists');
        self::assertSelectorTextContains('[role="alert"] h2', 'servers.json is held');
        $form = $crawler->filter('form[action$="/servers/release"]');

        // Without a reason nothing happens; with one the list is published.
        $this->client->submit($form->form(['reason' => '']));
        self::assertCount(1, $this->publishedList('servers')['entries']);
        $this->client->submit($form->form(['reason' => 'Launch batch reviewed by the board.']));
        self::assertCount(5, $this->publishedList('servers')['entries']);
    }
}
