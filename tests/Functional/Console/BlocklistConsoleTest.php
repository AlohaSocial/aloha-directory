<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Entity\BlockedDomain;
use App\Entity\ServerCheck;
use App\Enum\PauseKind;
use App\Enum\ServerState;
use App\Service\Blocklist;
use App\Tests\Support\ConsoleBrowsing;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\TestKeys;

/**
 * The directory's own block list in the console, and its effect on enrolment and listed servers.
 */
final class BlocklistConsoleTest extends FunctionalTestCase
{
    use ConsoleBrowsing;

    public function testCuratorBlocksAndUnblocksADomain(): void
    {
        $this->createCurator('bob@nextcloud.test');
        $this->consoleLogin('bob@nextcloud.test');

        $crawler = $this->page('/console/blocklist');
        self::assertSelectorTextContains('table tbody', 'Empty.');
        $this->submitForm($crawler, 'form[action$="/console/blocklist/add"]', ['domain' => ' Spam.Example.ORG ', 'reason' => 'Spam farm.']);
        $crawler = $this->followFlash('success', 'spam.example.org is blocked.', '/console/blocklist');
        self::assertSelectorTextContains('table tbody', 'spam.example.org');
        self::assertSelectorTextContains('table tbody', 'Spam farm.');
        self::assertSelectorTextContains('main ul', 'directory: 1 domains');

        $block = $this->em->getRepository(BlockedDomain::class)->findOneBy(['domain' => 'spam.example.org']);
        self::assertNotNull($block);
        self::assertSame(BlockedDomain::SOURCE_DIRECTORY, $block->source);
        $entry = $this->lastAudit('blocklist.added');
        self::assertSame('bob@nextcloud.test', $entry->actorLabel);
        self::assertSame(['domain' => 'spam.example.org', 'reason' => 'Spam farm.'], $entry->details);

        // The block covers subdomains: they cannot enrol.
        [, $public] = TestKeys::random();
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'social.spam.example.org', 'public_key' => TestKeys::encode($public)], null, 403);

        // Blocking it again keeps one entry with the first reason.
        $this->submitForm($this->page('/console/blocklist'), 'form[action$="/add"]', ['domain' => 'spam.example.org', 'reason' => 'Other reason.']);
        $this->followFlash('success', 'spam.example.org is blocked.');
        $blocks = $this->em->getRepository(BlockedDomain::class)->findBy(['domain' => 'spam.example.org']);
        self::assertCount(1, $blocks);
        self::assertSame('Spam farm.', $blocks[0]->reason);

        $crawler = $this->page('/console/blocklist');
        $this->submitForm($crawler, "form[action$=\"/console/blocklist/{$block->id}/remove\"]");
        $crawler = $this->followFlash('success', 'spam.example.org is no longer blocked.', '/console/blocklist');
        self::assertSelectorTextContains('table tbody', 'Empty.');
        self::assertSame([], $this->em->getRepository(BlockedDomain::class)->findAll());
        self::assertSame(['domain' => 'spam.example.org'], $this->lastAudit('blocklist.removed')->details);
        $this->api('POST', '/api/v1/servers/enrol', ['domain' => 'social.spam.example.org', 'public_key' => TestKeys::encode($public)], null, 201);
    }

    public function testInvalidDomainsAreRefused(): void
    {
        $this->createCurator('bob@nextcloud.test');
        $this->consoleLogin('bob@nextcloud.test');
        foreach ([
            'https://evil.example.org/' => 'must not contain a scheme',
            '' => 'Invalid domain.',
            '10.0.0.1' => 'An IP address is not a domain.',
            'intranet.local' => 'cannot host a public server',
            'localhost' => 'at least two labels',
        ] as $domain => $message) {
            $this->submitForm($this->page('/console/blocklist'), 'form[action$="/add"]', ['domain' => $domain, 'reason' => 'x']);
            $this->followFlash('error', $message, '/console/blocklist');
        }
        self::assertSame([], $this->em->getRepository(BlockedDomain::class)->findAll());
        self::assertSame([], $this->audit('blocklist.added'));
    }

    public function testImportedListsAreSearchableButNotRemovableInTheConsole(): void
    {
        $blocklist = static::getContainer()->get(Blocklist::class);
        $result = $blocklist->importSource('oliphant', "#domain,#severity\nbad.example.net,suspend\n\"worse.example.net\",suspend\n*.hidden.example.net,suspend\nnot a domain,suspend\n// comment\n\nwith_100%.example.com,suspend\n");
        $this->em->flush();
        self::assertSame(['added' => 2, 'removed' => 0, 'skipped' => 3], $result);
        // A re-import replaces the source.
        self::assertSame(['added' => 1, 'removed' => 1, 'skipped' => 0], $blocklist->importSource('oliphant', "bad.example.net\nnew.example.net\n"));
        $this->em->flush();
        try {
            $blocklist->importSource(BlockedDomain::SOURCE_DIRECTORY, 'x.example.org');
            self::fail('The directory source cannot be imported.');
        } catch (\InvalidArgumentException) {
        }

        $this->createCurator('bob@nextcloud.test');
        $this->consoleLogin('bob@nextcloud.test');
        $this->page('/console/blocklist');
        self::assertSelectorTextContains('main ul', 'oliphant: 2 domains');
        self::assertSelectorTextContains('table tbody', 'Empty.', 'imported entries are not part of the own list');

        $crawler = $this->page('/console/blocklist?q=BAD.example');
        self::assertSame(['bad.example.net (oliphant): Imported from oliphant'], $crawler->filter('section.card ul')->eq(1)->filter('li')->each(static fn ($li): string => $li->text()));
        // LIKE wildcards are matched literally.
        $crawler = $this->page('/console/blocklist?q=%25');
        self::assertSame(['No match.'], $crawler->filter('section.card ul')->eq(1)->filter('li')->each(static fn ($li): string => $li->text()));
        $crawler = $this->page('/console/blocklist?q=_');
        self::assertSame(['No match.'], $crawler->filter('section.card ul')->eq(1)->filter('li')->each(static fn ($li): string => $li->text()));

        $imported = $this->em->getRepository(BlockedDomain::class)->findOneBy(['domain' => 'bad.example.net']);
        self::assertNotNull($imported);
        $this->post("/console/blocklist/{$imported->id}/remove");
        self::assertResponseStatusCodeSame(404);
        $this->post('/console/blocklist/999/remove');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::$base.'/console/blocklist/add', ['domain' => 'csrf.example.org', 'reason' => 'x', '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(2, $this->em->getRepository(BlockedDomain::class)->findAll());
        self::assertSame([], $this->audit('blocklist.removed'));
    }

    public function testBlockingAListedServerPausesItOnTheNextChecks(): void
    {
        $this->listServer($this->enrolServer('social.example.org'));
        $this->consoleLogin('bob@nextcloud.test');
        $this->submitForm($this->page('/console/blocklist'), 'form[action$="/add"]', ['domain' => 'social.example.org', 'reason' => 'Hosts a harassment campaign.']);
        $this->followFlash('success', 'social.example.org is blocked.');
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state);

        // "Run checks now" twice: two failures of a critical check pause the server.
        $crawler = $this->page('/console/servers/social.example.org');
        $this->submitForm($crawler, 'form[action$="/social.example.org/check"]');
        $crawler = $this->followFlash('success', 'The automatic checks ran again.', '/console/servers/social.example.org');
        self::assertSelectorTextContains('ul.checks', 'Blocked by "directory" (social.example.org): Hosts a harassment campaign.');
        self::assertSame(ServerState::Listed, $this->server('social.example.org')->state);
        $this->submitForm($crawler, 'form[action$="/social.example.org/check"]');
        $this->followFlash('success', 'The automatic checks ran again.');
        $server = $this->server('social.example.org');
        self::assertSame(ServerState::Paused, $server->state);
        self::assertSame(PauseKind::Checks, $server->pauseKind);
        $check = $this->em->getRepository(ServerCheck::class)->findOneBy(['server' => $server, 'name' => 'blocklist']);
        self::assertNotNull($check);
        self::assertFalse($check->passed);
        self::assertSame(2, $check->consecutiveFailures);

        // Unknown or malformed domains.
        foreach (['/console/servers/unknown.example.org/check', '/console/servers/bad..domain/check'] as $path) {
            $this->post($path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }
}
