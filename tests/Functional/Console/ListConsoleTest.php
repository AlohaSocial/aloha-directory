<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Curation\CurationService;
use App\Entity\ListBuild;
use App\Entity\ListState;
use App\Enum\Decision;
use App\Tests\Support\ConsoleBrowsing;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * List builds in the console: history, build view, diffs, forced rebuilds and releasing held builds.
 */
final class ListConsoleTest extends FunctionalTestCase
{
    use ConsoleBrowsing;

    public function testCuratorRebuildsAndResignsTheLists(): void
    {
        $this->installKeySet();
        $this->listServer($this->enrolServer('s0.example.org'));
        $this->tick(true);
        $serial = (int) $this->publishedList('servers')['serial'];
        $builds = \count($this->em->getRepository(ListBuild::class)->findAll());

        $this->consoleLogin('bob@nextcloud.test');
        $crawler = $this->page('/console/lists');
        self::assertCount(3, $crawler->filter('div.grid3 section.card'));
        $this->submitForm($crawler, 'form[action$="/console/lists/rebuild"]');
        $this->followFlash('success', 'Published: ', '/console/lists');
        self::assertSelectorTextContains('main > .flash-success', 'servers#'.($serial + 1));

        self::assertGreaterThan($serial, (int) $this->publishedList('servers')['serial']);
        self::assertGreaterThan($builds, \count($this->em->getRepository(ListBuild::class)->findAll()));
        $entry = $this->lastAudit('list.rebuild_requested');
        self::assertSame('bob@nextcloud.test', $entry->actorLabel);

        $this->client->request('POST', self::$base.'/console/lists/rebuild', ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->audit('list.rebuild_requested'));
    }

    public function testHistoryBuildViewAndDiff(): void
    {
        $this->installKeySet();
        $this->listServer($this->enrolServer('s0.example.org'));
        $this->listServer($this->enrolServer('s1.example.org'));
        $this->tick(true);
        $first = $this->latestBuild('servers');

        // s2 is added and s0's weight changes; s1 is paused and leaves the list.
        $this->listServer($this->enrolServer('s2.example.org'));
        $curation = static::getContainer()->get(CurationService::class);
        $curation->decide($this->curator('alice@nextcloud.test'), $this->server('s0.example.org'), Decision::Weight, ['weight' => '1.5'], 'Strong team.');
        $curation->pause($this->curator('alice@nextcloud.test'), $this->server('s1.example.org'), 'Investigating.');
        $this->tick(true);
        $second = $this->latestBuild('servers');
        self::assertNotSame($first->id, $second->id);

        $this->consoleLogin('bob@nextcloud.test');
        $crawler = $this->page('/console/lists');
        self::assertSame((string) $second->id, $crawler->filter('input[name="b"][checked]')->attr('value'), 'newest build preselected as B');
        self::assertSame((string) $first->id, $crawler->filter('input[name="a"][checked]')->attr('value'));

        // The compare form (GET) shows the differences.
        $crawler = $this->client->submit($crawler->selectButton('Compare A and B')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "servers.json: serial {$first->serial} → {$second->serial}");
        $sections = $this->diffSections($crawler);
        self::assertSame(['s2.example.org'], $sections['Added']);
        self::assertSame(['s1.example.org'], $sections['Removed']);
        self::assertSame(['s0.example.org'], $crawler->filter('section.card h3')->each(static fn (Crawler $h): string => $h->text()));
        self::assertStringContainsString('weight', $crawler->filter('section.card')->text());

        // Swapped A and B are put in order.
        $crawler = $this->page("/console/lists/diff?a={$second->id}&b={$first->id}");
        self::assertSelectorTextContains('h1', "serial {$first->serial} → {$second->serial}");
        self::assertSame(['s2.example.org'], $this->diffSections($crawler)['Added']);

        // The same build twice: nothing changed.
        $crawler = $this->page("/console/lists/diff?a={$second->id}&b={$second->id}");
        self::assertSame([], $this->diffSections($crawler)['Added']);
        self::assertSelectorTextContains('section.card', 'No entry changed.');

        // Builds of different lists, or unknown builds, cannot be compared.
        $search = $this->latestBuild('search');
        foreach (["a={$first->id}&b={$search->id}", "a={$first->id}&b=999999", 'a=0&b=0', ''] as $query) {
            $this->client->request('GET', self::$base.'/console/lists/diff?'.$query);
            self::assertResponseStatusCodeSame(404, $query);
        }

        // The build view shows the signed payload.
        $this->client->click($this->page('/console/lists')->filter('div.grid3 section.card')->first()->filter('dd a')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "servers.json serial {$second->serial}");
        self::assertSelectorTextContains('pre.json', '"domain": "s2.example.org"');
        $this->client->request('GET', self::$base.'/console/lists/999999');
        self::assertResponseStatusCodeSame(404);

        // History tabs; an unknown list falls back to servers.
        $crawler = $this->page('/console/lists?list=search');
        self::assertSame('search', $crawler->filter('nav.tabs a[aria-current]')->text());
        $crawler = $this->page('/console/lists?list=bogus&page=0');
        self::assertSame('servers', $crawler->filter('nav.tabs a[aria-current]')->text());
        $crawler = $this->page('/console/lists?page=2');
        self::assertSelectorTextContains('table tbody', 'No builds yet.');
        self::assertCount(1, $crawler->filter('a:contains("Newer")'));
    }

    public function testReleasingAHeldBuildNeedsTheSeenChangeAndAReason(): void
    {
        $this->installKeySet();
        $this->listServer($this->enrolServer('s0.example.org'));
        $this->tick();
        for ($i = 1; $i <= 4; ++$i) {
            $this->listServer($this->enrolServer("s{$i}.example.org"));
        }
        $this->tick();
        self::assertCount(1, $this->publishedList('servers')['entries']);
        $state = $this->em->find(ListState::class, 'servers');
        self::assertNotNull($state?->heldHash);
        $held = $state->heldHash;

        $this->consoleLogin('bob@nextcloud.test');
        $crawler = $this->page('/console/lists');
        self::assertSelectorTextContains('[role="alert"]', 'would add 4 and remove 0 of 1 entries');
        self::assertSame($held, $crawler->filter('form[action$="/servers/release"] input[name="held_hash"]')->attr('value'));

        $this->submitForm($crawler, 'form[action$="/servers/release"]', ['reason' => '   ']);
        $this->followFlash('error', 'Please say why this change is intended.', '/console/lists');
        $this->post('/console/lists/servers/release', ['held_hash' => str_repeat('0', 64), 'reason' => 'Looks fine.']);
        $this->followFlash('error', 'The held change is different now (or was resolved). Please look again.');
        $this->post('/console/lists/servers/release', ['reason' => 'Looks fine.']);
        $this->followFlash('error', 'The held change is different now');
        $this->post('/console/lists/search/release', ['held_hash' => $held, 'reason' => 'Wrong list.']);
        $this->followFlash('error', 'The held change is different now', '/console/lists');
        $this->post('/console/lists/keys/release', ['held_hash' => $held, 'reason' => 'x']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->audit('list.released'));
        self::assertCount(1, $this->publishedList('servers')['entries']);

        $this->submitForm($this->page('/console/lists'), 'form[action$="/servers/release"]', ['reason' => 'Launch batch reviewed by the board.']);
        $crawler = $this->followFlash('success', 'Released and published: servers#', '/console/lists');
        self::assertCount(0, $crawler->filter('[role="alert"] form'), 'nothing is held any more');
        self::assertCount(5, $this->publishedList('servers')['entries']);
        $entry = $this->lastAudit('list.released');
        self::assertSame('bob@nextcloud.test', $entry->actorLabel);
        self::assertSame('servers', $entry->details['list']);
        self::assertSame('Launch batch reviewed by the board.', $entry->details['reason']);
        self::assertCount(4, $entry->details['added']);

        // A second release of the same (now resolved) change does nothing.
        $this->post('/console/lists/servers/release', ['held_hash' => $held, 'reason' => 'Again.']);
        $this->followFlash('error', 'The held change is different now');
        self::assertCount(1, $this->audit('list.released'));
    }

    private function latestBuild(string $list): ListBuild
    {
        $build = $this->em->getRepository(ListBuild::class)->findOneBy(['list' => $list], ['id' => 'DESC']);
        self::assertNotNull($build, $list);

        return $build;
    }

    /**
     * @return array<string, list<string>> the "Added" and "Removed" lists of the diff page
     */
    private function diffSections(Crawler $crawler): array
    {
        $out = [];
        foreach ($crawler->filter('section.card h2') as $heading) {
            $title = trim($heading->textContent);
            $next = $heading->nextSibling;
            while (null !== $next && !$next instanceof \DOMElement) {
                $next = $next->nextSibling;
            }
            $out[$title] = $next instanceof \DOMElement && 'ul' === $next->nodeName
                ? array_values(array_map(static fn (\DOMNode $li): string => trim($li->textContent), iterator_to_array($next->getElementsByTagName('li'))))
                : [];
        }

        return $out;
    }
}
