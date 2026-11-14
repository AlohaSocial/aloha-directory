<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Curation\CurationException;
use App\Entity\BlockedDomain;
use App\Entity\CuratorInvite;
use App\Service\Blocklist;
use App\Tests\Support\ConsoleCommandTestCase;

/**
 * Operator commands: app:blocklist:import and app:curator:invite.
 */
final class AdminCommandsTest extends ConsoleCommandTestCase
{
    private const MASTODON_CSV = <<<'CSV'
        #domain,#severity,#reject_media,#reject_reports,#public_comment,#obfuscate
        spam.example.org,suspend,false,false,Spam,false
        Hate.Example.NET,suspend,true,true,Harassment,false
        "quoted.example.com",suspend,false,false,,false
        bad*.example.org,suspend,false,false,Obfuscated,true
        https://not-a-domain.example.org/,suspend,false,false,,false
        192.0.2.1,suspend,false,false,,false
        spam.example.org,silence,false,false,Duplicate,false
        CSV;

    public function testImportReadsAMastodonExport(): void
    {
        $file = $this->scratchFile($this->scratchDir(), 'tier0.csv', self::MASTODON_CSV);

        [$exit, $output] = $this->console('app:blocklist:import', ['source' => 'oliphant-tier0', 'file' => $file]);
        self::assertSame(0, $exit);
        self::assertSame('[OK] oliphant-tier0: 3 added, 0 removed, 3 lines skipped.', $output);
        self::assertSame(['hate.example.net', 'quoted.example.com', 'spam.example.org'], $this->blocked('oliphant-tier0'));

        $audit = $this->auditEntries('blocklist.imported');
        self::assertCount(1, $audit);
        self::assertSame('system', $audit[0]->actorType);
        self::assertSame(['source' => 'oliphant-tier0', 'added' => 3, 'removed' => 0, 'skipped' => 3], $audit[0]->details);

        // Subdomains of a blocked domain are blocked too.
        self::assertSame('oliphant-tier0', static::getContainer()->get(Blocklist::class)->match('social.hate.example.net')?->source);
    }

    public function testReimportReplacesOnlyItsOwnSource(): void
    {
        $dir = $this->scratchDir();
        static::getContainer()->get(Blocklist::class)->add('spam.example.org', BlockedDomain::SOURCE_DIRECTORY, 'Our own decision.');
        $this->em->flush();
        $this->console('app:blocklist:import', ['source' => 'oliphant-tier0', 'file' => $this->scratchFile($dir, 'v1.csv', self::MASTODON_CSV)]);
        $this->console('app:blocklist:import', ['source' => 'other-list', 'file' => $this->scratchFile($dir, 'other.txt', "other.example.org\n")]);

        // A plain list, one domain per line, with comments.
        $plain = $this->scratchFile($dir, 'v2.txt', "// tier-0, updated\nspam.example.org\n\n  new.example.org  \r\nquoted.example.com\n");
        [$exit, $output] = $this->console('app:blocklist:import', ['source' => 'oliphant-tier0', 'file' => $plain]);
        self::assertSame(0, $exit);
        self::assertSame('[OK] oliphant-tier0: 1 added, 1 removed, 0 lines skipped.', $output);
        self::assertSame(['new.example.org', 'quoted.example.com', 'spam.example.org'], $this->blocked('oliphant-tier0'));
        self::assertSame(['other.example.org'], $this->blocked('other-list'));
        self::assertSame(['spam.example.org'], $this->blocked(BlockedDomain::SOURCE_DIRECTORY), 'the directory\'s own blocks stay');

        // Importing the same file again changes nothing.
        self::assertSame([0, '[OK] oliphant-tier0: 0 added, 0 removed, 0 lines skipped.'], $this->console('app:blocklist:import', ['source' => 'oliphant-tier0', 'file' => $plain]));
        self::assertCount(4, $this->auditEntries('blocklist.imported'));

        // An empty file empties the source.
        self::assertSame([0, '[OK] oliphant-tier0: 0 added, 3 removed, 0 lines skipped.'], $this->console('app:blocklist:import', ['source' => 'oliphant-tier0', 'file' => $this->scratchFile($dir, 'empty.txt', '')]));
        self::assertSame([], $this->blocked('oliphant-tier0'));
    }

    public function testImportRefusesBadSourcesAndFiles(): void
    {
        $dir = $this->scratchDir();
        $file = $this->scratchFile($dir, 'tier0.csv', self::MASTODON_CSV);

        foreach (['Oliphant', '-tier0', 'a/b', '../x', '', str_repeat('a', 65)] as $source) {
            [$exit, $output] = $this->console('app:blocklist:import', ['source' => $source, 'file' => $file]);
            self::assertSame(2, $exit, $source);
            self::assertStringContainsString('The source name may contain a-z, 0-9, ".", "_" and "-".', $output);
        }

        [$exit, $output] = $this->console('app:blocklist:import', ['source' => 'oliphant-tier0', 'file' => $dir.'/missing.csv']);
        self::assertSame(1, $exit);
        self::assertOutputContains('Cannot read '.$dir.'/missing.csv', $output);

        // The directory's own list is edited in the console, never replaced by a file.
        static::getContainer()->get(Blocklist::class)->add('own.example.org', BlockedDomain::SOURCE_DIRECTORY, 'Our own decision.');
        $this->em->flush();
        try {
            $this->console('app:blocklist:import', ['source' => BlockedDomain::SOURCE_DIRECTORY, 'file' => $file]);
            self::fail('The directory block list was replaced.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('managed in the console', $e->getMessage());
        }
        self::assertSame(['own.example.org'], $this->blocked(BlockedDomain::SOURCE_DIRECTORY));

        self::assertSame([], $this->blocked('oliphant-tier0'));
        self::assertSame([], $this->auditEntries('blocklist.imported'));
    }

    public function testInviteCreatesASingleUseLinkForANewCurator(): void
    {
        [$exit, $output] = $this->console('app:curator:invite', ['email' => '  New.Curator@Nextcloud.TEST ', 'name' => ' Nora ', '--admin' => true]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('[OK] Invitation created. It is valid for 72 hours:', $output);
        self::assertSame(1, preg_match('~ (https://directory\.test-aloha\.social/console/invite/([0-9a-f]{64}))\z~', $output, $m), $output);
        [, $url, $token] = $m;

        $invites = $this->em->getRepository(CuratorInvite::class)->findAll();
        self::assertCount(1, $invites);
        $invite = $invites[0];
        self::assertSame('new.curator@nextcloud.test', $invite->email);
        self::assertSame('Nora', $invite->displayName);
        self::assertTrue($invite->admin);
        self::assertNull($invite->invitedBy);
        self::assertSame(hash('sha256', $token), $invite->tokenHash, 'only the hash of the token is stored');
        self::assertEquals($this->clock->now()->modify('+72 hours'), $invite->expiresAt);

        $audit = $this->auditEntries('curator.invited');
        self::assertCount(1, $audit);
        self::assertSame('system', $audit[0]->actorType);
        self::assertSame(['email' => 'new.curator@nextcloud.test', 'admin' => true], $audit[0]->details);

        // The invitee gets the link by mail, and the link opens the invitation form.
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'new.curator@nextcloud.test');
        self::assertEmailTextBodyContains($mail, $url);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        // Without --admin, the invitee becomes a plain curator; after 72 hours the link is dead.
        $this->console('app:curator:invite', ['email' => 'second@nextcloud.test', 'name' => 'Sam']);
        $second = $this->em->getRepository(CuratorInvite::class)->findOneBy(['email' => 'second@nextcloud.test']);
        self::assertNotNull($second);
        self::assertFalse($second->admin);
        $this->clock->sleep(72 * 3600 + 1);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInviteRefusesBadInput(): void
    {
        $this->createCurator('alice@nextcloud.test', true);

        foreach ([
            ['not-an-address', 'Nora', 'valid e-mail address'],
            ['nora@nextcloud.test', '   ', 'at most 100 characters'],
            ['nora@nextcloud.test', str_repeat('n', 101), 'at most 100 characters'],
            ['Alice@Nextcloud.test', 'Alice again', 'exists already'],
        ] as [$email, $name, $message]) {
            try {
                $this->console('app:curator:invite', ['email' => $email, 'name' => $name]);
                self::fail('An invitation was created for '.$email);
            } catch (CurationException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertSame([], $this->em->getRepository(CuratorInvite::class)->findAll());
        self::assertSame([], $this->auditEntries('curator.invited'));
        self::assertEmailCount(0);
    }

    /**
     * @return list<string>
     */
    private function blocked(string $source): array
    {
        $this->em->clear();
        $domains = array_map(static fn (BlockedDomain $b): string => $b->domain, $this->em->getRepository(BlockedDomain::class)->findBy(['source' => $source]));
        sort($domains);

        return $domains;
    }
}
