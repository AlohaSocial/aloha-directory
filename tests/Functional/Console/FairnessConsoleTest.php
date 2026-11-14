<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Tests\Support\ConsoleBrowsing;
use App\Tests\Support\FakeAlohaServer;
use App\Tests\Support\Fixtures;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The fairness page: how newcomers spread across featured servers.
 */
final class FairnessConsoleTest extends FunctionalTestCase
{
    use ConsoleBrowsing;

    public function testPageWithoutFeaturedServers(): void
    {
        $this->listServer($this->enrolServer('social.example.org'));
        $this->consoleLogin('bob@nextcloud.test');
        $crawler = $this->page('/console/fairness');
        self::assertSelectorTextContains('h1', 'How newcomers spread');
        self::assertSame(['0', '0%', '0.00'], $crawler->filter('p.big')->each(static fn (Crawler $p): string => $p->text()));
        self::assertSelectorTextContains('main', 'Herfindahl index; – would be perfectly even');
        self::assertSame(3, $crawler->filter('td.empty')->count());
        // The settings in force are explained.
        self::assertSelectorTextContains('main > p', 'more than 150% of its fair share');
        self::assertSelectorTextContains('main > p', 'at least two featured servers and 50 new accounts');
    }

    public function testPageShowsTheSpreadPerLanguageCountryAndServer(): void
    {
        $fakes = [];
        foreach (['big', 'small'] as $name) {
            $fakes[$name] = $this->enrolServer($name.'.example.org');
            $this->listServer($fakes[$name]);
            $this->server($name.'.example.org')->featured = true;
            $this->em->flush();
        }
        // A listed server that is not featured does not count.
        $this->listServer($this->enrolServer('plain.example.org'));

        $this->clock->sleep(7 * 86400 + 3600);
        $this->usersHeartbeat($fakes['big'], 1000);
        $this->usersHeartbeat($fakes['small'], 1000);
        $this->clock->sleep(6 * 86400);
        $this->usersHeartbeat($fakes['big'], 1300);
        $this->usersHeartbeat($fakes['small'], 1100);

        $this->consoleLogin('bob@nextcloud.test');
        $crawler = $this->page('/console/fairness');
        self::assertSame(['400', '75%', '0.63'], $crawler->filter('p.big')->each(static fn (Crawler $p): string => $p->text()));
        self::assertSelectorTextContains('main', 'on 2 featured servers');
        self::assertSelectorTextContains('main', '0.50 would be perfectly even');

        $tables = $crawler->filter('table');
        self::assertSame([['de', '2', '400', '75%', '0.63', 'yes']], $this->rows($tables->eq(0)));
        self::assertSame([['DE', '2', '400', '100%']], $this->rows($tables->eq(1)));
        $servers = $this->rows($tables->eq(2));
        self::assertCount(2, $servers);
        self::assertSame(['Example Social', 'de', '300', '75%', '50%'], \array_slice($servers[0], 0, 5));
        self::assertSame(['Example Social', 'de', '100', '25%', '50%'], \array_slice($servers[1], 0, 5));
        self::assertSame(['/console/servers/big.example.org', '/console/servers/small.example.org'], $tables->eq(2)->filter('a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertStringNotContainsString('plain.example.org', $tables->eq(2)->text());
    }

    /**
     * @return list<list<string>>
     */
    private function rows(Crawler $table): array
    {
        return $table->filter('tbody tr')->each(static fn (Crawler $tr): array => $tr->filter('td')->each(static fn (Crawler $td): string => trim($td->text())));
    }

    private function usersHeartbeat(FakeAlohaServer $fake, int $usersTotal): void
    {
        $hb = Fixtures::heartbeat($fake->domain, $this->clock->now());
        $hb['usage']['users_total'] = $usersTotal;
        $this->api('PUT', "/api/v1/servers/{$fake->domain}/heartbeat", $hb, $fake);
    }
}
