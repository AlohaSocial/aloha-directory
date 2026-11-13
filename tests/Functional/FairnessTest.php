<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fairness\FairnessCalculator;
use App\Tests\Support\FakeAlohaServer;
use App\Tests\Support\Fixtures;
use App\Tests\Support\FunctionalTestCase;

final class FairnessTest extends FunctionalTestCase
{
    public function testServerTakingMostNewcomersIsBalanced(): void
    {
        $this->installKeySet();
        $fakes = [];
        foreach (['big', 'small1', 'small2'] as $name) {
            $fakes[$name] = $this->enrolServer($name.'.example.org');
            $this->listServer($fakes[$name]);
            $this->server($name.'.example.org')->featured = true;
            $this->em->flush();
        }

        // Day 0 totals (after the heartbeats of the review have left the window), then six days
        // later: big +900, the others +50 each.
        $this->clock->sleep(7 * 86400 + 3600);
        $this->usersHeartbeat($fakes['big'], 1000);
        $this->usersHeartbeat($fakes['small1'], 1000);
        $this->usersHeartbeat($fakes['small2'], 1000);
        $this->clock->sleep(6 * 86400);
        $this->usersHeartbeat($fakes['big'], 1900);
        $this->usersHeartbeat($fakes['small1'], 1050);
        $this->usersHeartbeat($fakes['small2'], 1050);

        $calculator = static::getContainer()->get(FairnessCalculator::class);
        $report = $calculator->recompute();
        self::assertSame(1000, $report['total_new_accounts']);
        self::assertSame(0.9, $report['top_share']);
        self::assertTrue($report['cohorts']['de']['balancing']);
        self::assertSame(55, $report['servers']['big.example.org']['target_balance']);

        // Halfway towards the target, in steps of 5%.
        self::assertSame(80, $this->server('big.example.org')->balancePercent);
        self::assertSame(100, $this->server('small1.example.org')->balancePercent);
        self::assertSame(900, $this->server('big.example.org')->newAccounts7d);

        $this->tick(true);
        $entries = array_column($this->publishedList('servers')['entries'], null, 'domain');
        self::assertSame(0.8, $entries['big.example.org']['balance']);
        self::assertSame(1, $entries['small1.example.org']['balance']);
        self::assertArrayNotHasKey('new_accounts', $entries['big.example.org'], 'exact numbers stay internal');

        // When the spread evens out, the factor returns to 1.
        $this->clock->sleep(8 * 86400);
        foreach ($fakes as $fake) {
            $this->usersHeartbeat($fake, 2000);
        }
        $this->clock->sleep(3600);
        foreach ($fakes as $fake) {
            $this->usersHeartbeat($fake, 2100);
        }
        $calculator->recompute();
        self::assertSame(90, $this->server('big.example.org')->balancePercent);
        $calculator->recompute();
        self::assertSame(100, $this->server('big.example.org')->balancePercent);
    }

    public function testNoBalancingWithTooFewNewcomers(): void
    {
        $fakes = [];
        foreach (['a', 'b'] as $name) {
            $fakes[$name] = $this->enrolServer($name.'.example.org');
            $this->listServer($fakes[$name]);
            $this->server($name.'.example.org')->featured = true;
            $this->em->flush();
        }
        $this->clock->sleep(7 * 86400 + 3600);
        $this->usersHeartbeat($fakes['a'], 100);
        $this->usersHeartbeat($fakes['b'], 100);
        $this->clock->sleep(3600);
        $this->usersHeartbeat($fakes['a'], 130);
        $this->usersHeartbeat($fakes['b'], 100);

        $report = static::getContainer()->get(FairnessCalculator::class)->recompute();
        self::assertFalse($report['cohorts']['de']['balancing']);
        self::assertSame(100, $this->server('a.example.org')->balancePercent);
    }

    private function usersHeartbeat(FakeAlohaServer $fake, int $usersTotal): void
    {
        $hb = Fixtures::heartbeat($fake->domain, $this->clock->now());
        $hb['usage']['users_total'] = $usersTotal;
        $this->api('PUT', "/api/v1/servers/{$fake->domain}/heartbeat", $hb, $fake);
    }
}
