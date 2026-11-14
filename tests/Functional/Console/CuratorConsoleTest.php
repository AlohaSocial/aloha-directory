<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Entity\Curator;
use App\Entity\CuratorConflict;
use App\Entity\CuratorInvite;
use App\Tests\Support\ConsoleBrowsing;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\SoftAuthenticator;

/**
 * Curator administration (invitations, deactivation, roles, conflicts of interest) through the console.
 */
final class CuratorConsoleTest extends FunctionalTestCase
{
    use ConsoleBrowsing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCurator('alice@nextcloud.test', true);
        $this->createCurator('bob@nextcloud.test');
    }

    public function testAdminInvitesACurator(): void
    {
        $this->consoleLogin('alice@nextcloud.test');
        $crawler = $this->page('/console/curators');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->submitForm($crawler, 'form[action$="/console/curators/invite"]', ['name' => 'Carol Curator', 'email' => ' Carol@Nextcloud.test ', 'admin' => '1']);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'carol@nextcloud.test');
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $mail);
        $mailText = (string) $mail->getTextBody();
        $crawler = $this->followFlash('success', 'Invitation created and sent by e-mail.', '/console/curators');

        // The link is shown once to the admin.
        $shown = $crawler->filter('.flash-info .mono');
        self::assertCount(1, $shown);
        $url = $shown->text();
        self::assertMatchesRegularExpression('#^https://directory\.test-aloha\.social/console/invite/[0-9a-f]{64}$#', $url);
        self::assertStringContainsString($url, $mailText);
        self::assertSelectorTextContains('main ul', 'carol@nextcloud.test');
        $this->page('/console/curators');
        self::assertSelectorNotExists('.flash-info');

        $invite = $this->em->getRepository(CuratorInvite::class)->findOneBy(['email' => 'carol@nextcloud.test']);
        self::assertNotNull($invite);
        self::assertTrue($invite->admin);
        self::assertSame('Carol Curator', $invite->displayName);
        self::assertSame('alice@nextcloud.test', $invite->invitedBy?->email);
        $entry = $this->lastAudit('curator.invited');
        self::assertSame('alice@nextcloud.test', $entry->actorLabel);
        self::assertSame(['email' => 'carol@nextcloud.test', 'admin' => true], $entry->details);

        // The invitee can open the link.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-passkey-create]');
    }

    public function testInvitationWithoutTheAdminBoxIsForACurator(): void
    {
        $this->consoleLogin('alice@nextcloud.test');
        $this->submitForm($this->page('/console/curators'), 'form[action$="/invite"]', ['name' => 'Dave', 'email' => 'dave@nextcloud.test']);
        $this->followFlash('success', 'Invitation created');
        $invite = $this->em->getRepository(CuratorInvite::class)->findOneBy(['email' => 'dave@nextcloud.test']);
        self::assertNotNull($invite);
        self::assertFalse($invite->admin);
        self::assertFalse($this->lastAudit('curator.invited')->details['admin']);
    }

    public function testInvalidInvitationsAreRefused(): void
    {
        $this->consoleLogin('alice@nextcloud.test');
        $cases = [
            'Enter a valid e-mail address.' => ['name' => 'X', 'email' => 'not-an-address'],
            'Enter a name of at most 100 characters.' => ['name' => '   ', 'email' => 'eve@nextcloud.test'],
            'at most 100 characters' => ['name' => str_repeat('n', 101), 'email' => 'eve@nextcloud.test'],
            'A curator with this e-mail address exists already.' => ['name' => 'Bob again', 'email' => 'BOB@nextcloud.test'],
        ];
        foreach ($cases as $message => $values) {
            $this->submitForm($this->page('/console/curators'), 'form[action$="/invite"]', $values);
            $crawler = $this->followFlash('error', $message, '/console/curators');
            self::assertCount(0, $crawler->filter('.flash-info'), 'no link for a refused invitation');
        }
        self::assertSame([], $this->em->getRepository(CuratorInvite::class)->findAll());
        self::assertSame([], $this->audit('curator.invited'));
        self::assertEmailCount(0);
    }

    public function testCuratorsWithoutTheAdminRoleCannotManageCurators(): void
    {
        $alice = $this->curator('alice@nextcloud.test');
        $this->enrolOneServer();
        $this->consoleLogin('bob@nextcloud.test');
        $token = $this->consoleToken();

        $this->client->request('GET', self::$base.'/console/curators');
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorNotExists('nav a[href="/console/curators"]');
        foreach ([
            ['/console/curators/invite', ['name' => 'Mallory', 'email' => 'mallory@evil.test', 'admin' => '1']],
            ["/console/curators/{$alice->id}/deactivate", []],
            ["/console/curators/{$alice->id}/remove-admin", []],
            ['/console/curators/conflicts', ['curator' => (string) $alice->id, 'domain' => 'social.example.org', 'note' => 'x']],
        ] as [$path, $fields]) {
            $this->post($path, $fields, $token);
            self::assertResponseStatusCodeSame(403, $path);
        }
        $alice = $this->curator('alice@nextcloud.test');
        self::assertTrue($alice->active);
        self::assertTrue($alice->isAdmin());
        self::assertSame([], $this->em->getRepository(CuratorInvite::class)->findAll());
        self::assertSame([], $this->em->getRepository(CuratorConflict::class)->findAll());

        // A curator cannot make themselves admin either.
        $bob = $this->curator('bob@nextcloud.test');
        $this->post("/console/curators/{$bob->id}/make-admin", [], $token);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->curator('bob@nextcloud.test')->isAdmin());
    }

    public function testDeactivationEndsTheSessionAndActivationRestoresAccess(): void
    {
        $bob = $this->curator('bob@nextcloud.test');
        $this->signInAs('bob@nextcloud.test');
        $bobSession = $this->saveSession();
        $this->page('/console');

        $this->signInAs('alice@nextcloud.test');
        $crawler = $this->page('/console/curators');
        self::assertCount(0, $crawler->filter('form[action$="/'.$this->curator('alice@nextcloud.test')->id.'/deactivate"]'), 'no button for oneself');
        $this->submitForm($crawler, "form[action$=\"/console/curators/{$bob->id}/deactivate\"]");
        $crawler = $this->followFlash('success', 'Curator deactivated; their sessions have ended.', '/console/curators');
        self::assertFalse($this->curator('bob@nextcloud.test')->active);
        self::assertSame(['curator' => 'bob@nextcloud.test'], $this->lastAudit('curator.deactivated')->details);
        self::assertSame('alice@nextcloud.test', $this->lastAudit('curator.deactivated')->actorLabel);
        self::assertGreaterThan(0, $crawler->filter("form[action$=\"/console/curators/{$bob->id}/activate\"]")->count());
        $aliceSession = $this->saveSession();

        // Bob's open session is over and he cannot sign in again.
        $this->restoreSession($bobSession);
        $this->client->request('GET', self::$base.'/console');
        self::assertResponseRedirects();
        self::assertStringContainsString('/console/login', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->getCookieJar()->clear();
        $this->consoleLogin('bob@nextcloud.test');
        self::assertResponseStatusCodeSame(401);

        $this->restoreSession($aliceSession);
        $this->submitForm($this->page('/console/curators'), "form[action$=\"/console/curators/{$bob->id}/activate\"]");
        $this->followFlash('success', 'Curator activated.');
        self::assertTrue($this->curator('bob@nextcloud.test')->active);
        self::assertCount(1, $this->audit('curator.activated'));
        $this->signInAs('bob@nextcloud.test');
    }

    public function testAdminsCannotLockThemselvesOut(): void
    {
        $alice = $this->curator('alice@nextcloud.test');
        $this->consoleLogin('alice@nextcloud.test');
        $this->post("/console/curators/{$alice->id}/deactivate");
        $this->followFlash('error', 'You cannot deactivate yourself.');
        $this->post("/console/curators/{$alice->id}/remove-admin");
        $this->followFlash('error', 'You cannot remove your own admin role.');
        $alice = $this->curator('alice@nextcloud.test');
        self::assertTrue($alice->active);
        self::assertTrue($alice->isAdmin());
        self::assertSame([], $this->audit('curator.deactivated'));
        self::assertSame([], $this->audit('curator.role_changed'));

        // Unknown curators and actions.
        $this->post('/console/curators/999/deactivate');
        self::assertResponseStatusCodeSame(404);
        $bob = $this->curator('bob@nextcloud.test');
        $this->post("/console/curators/{$bob->id}/delete");
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::$base."/console/curators/{$bob->id}/deactivate", ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->curator('bob@nextcloud.test')->active);
    }

    public function testAdminGivesAndRemovesTheAdminRole(): void
    {
        $bob = $this->curator('bob@nextcloud.test');
        $this->signInAs('alice@nextcloud.test');
        $this->submitForm($this->page('/console/curators'), "form[action$=\"/console/curators/{$bob->id}/make-admin\"]");
        $this->followFlash('success', 'Admin role given.');
        self::assertTrue($this->curator('bob@nextcloud.test')->isAdmin());
        self::assertSame(['curator' => 'bob@nextcloud.test', 'admin' => true], $this->lastAudit('curator.role_changed')->details);
        $aliceSession = $this->saveSession();

        $this->signInAs('bob@nextcloud.test');
        $this->page('/console/curators');

        $this->restoreSession($aliceSession);
        $this->submitForm($this->page('/console/curators'), "form[action$=\"/console/curators/{$bob->id}/remove-admin\"]");
        $this->followFlash('success', 'Admin role removed.');
        self::assertFalse($this->curator('bob@nextcloud.test')->isAdmin());
        self::assertSame(['curator' => 'bob@nextcloud.test', 'admin' => false], $this->lastAudit('curator.role_changed')->details);

        $this->signInAs('bob@nextcloud.test');
        $this->client->request('GET', self::$base.'/console/curators');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminRecordsAndRemovesConflictsOfInterest(): void
    {
        $this->enrolOneServer();
        $bob = $this->curator('bob@nextcloud.test');
        $alice = $this->curator('alice@nextcloud.test');
        $this->consoleLogin('alice@nextcloud.test');

        $selector = 'form[action$="/console/curators/conflicts"]';
        $this->submitForm($this->page('/console/curators'), $selector, ['curator' => (string) $bob->id, 'domain' => 'social.example.org', 'note' => 'Bob moderates it.']);
        $crawler = $this->followFlash('success', 'Conflict of interest recorded.', '/console/curators');
        self::assertStringContainsString('Bob moderates it.', $crawler->filter('section.card')->eq(1)->text());
        $conflicts = $this->em->getRepository(CuratorConflict::class)->findAll();
        self::assertCount(1, $conflicts);
        self::assertSame('bob@nextcloud.test', $conflicts[0]->curator->email);
        self::assertSame('social.example.org', $conflicts[0]->server->domain);
        $entry = $this->lastAudit('conflict.declared', 'social.example.org');
        self::assertSame('alice@nextcloud.test', $entry->actorLabel);
        self::assertSame('bob@nextcloud.test', $entry->details['curator']);

        // Recording it again does not add a second one.
        $this->submitForm($this->page('/console/curators'), $selector, ['curator' => (string) $bob->id, 'domain' => 'social.example.org', 'note' => 'Again.']);
        $this->followFlash('success', 'Conflict of interest recorded.');
        self::assertCount(1, $this->em->getRepository(CuratorConflict::class)->findAll());
        self::assertCount(1, $this->audit('conflict.declared'));

        // Unknown or invalid input.
        foreach ([
            ['curator' => (string) $bob->id, 'domain' => 'unknown.example.org', 'note' => 'x'],
            ['curator' => (string) $bob->id, 'domain' => 'https://social.example.org/', 'note' => 'x'],
            ['curator' => '999', 'domain' => 'social.example.org', 'note' => 'x'],
        ] as $fields) {
            $this->post('/console/curators/conflicts', $fields);
            $this->followFlash('error', 'Choose a curator and a known server domain.', '/console/curators');
        }
        self::assertCount(1, $this->em->getRepository(CuratorConflict::class)->findAll());

        // Alice records her own conflict; she cannot remove it herself, but Bob's she can.
        $this->post('/console/curators/conflicts', ['curator' => (string) $alice->id, 'domain' => 'social.example.org', 'note' => 'I host it.']);
        $this->followFlash('success', 'Conflict of interest recorded.');
        $own = $this->em->getRepository(CuratorConflict::class)->findOneBy(['curator' => $alice->id]);
        self::assertNotNull($own);
        $this->submitForm($this->page('/console/curators'), "form[action$=\"/console/curators/conflicts/{$own->id}/remove\"]");
        $this->followFlash('error', 'Another admin has to remove your own conflict of interest.');
        self::assertNotNull($this->em->find(CuratorConflict::class, $own->id));

        $bobs = $this->em->getRepository(CuratorConflict::class)->findOneBy(['curator' => $bob->id]);
        self::assertNotNull($bobs);
        $this->submitForm($this->page('/console/curators'), "form[action$=\"/console/curators/conflicts/{$bobs->id}/remove\"]");
        $this->followFlash('success', 'Conflict removed.');
        self::assertNull($this->em->find(CuratorConflict::class, $bobs->id));
        $entry = $this->lastAudit('conflict.removed', 'social.example.org');
        self::assertSame(['curator' => 'bob@nextcloud.test'], $entry->details);

        $this->post('/console/curators/conflicts/999/remove');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAdminCanDeactivateAnotherAdmin(): void
    {
        $carol = $this->createCurator('carol@nextcloud.test', true);
        $this->consoleLogin('alice@nextcloud.test');
        $this->submitForm($this->page('/console/curators'), "form[action$=\"/console/curators/{$carol->id}/deactivate\"]");
        $this->followFlash('success', 'Curator deactivated');
        self::assertFalse($this->curator('carol@nextcloud.test')->active);
        self::assertTrue($this->curator('alice@nextcloud.test')->active);
    }

    public function testASecondInvitationForTheSameAddressCannotBeUsedTwice(): void
    {
        $this->consoleLogin('alice@nextcloud.test');
        $links = [];
        for ($i = 0; $i < 2; ++$i) {
            $this->submitForm($this->page('/console/curators'), 'form[action$="/invite"]', ['name' => 'Dave', 'email' => 'dave@nextcloud.test']);
            $links[] = $this->followFlash('success', 'Invitation created')->filter('.flash-info .mono')->text();
        }
        self::assertNotSame($links[0], $links[1]);
        $this->client->getCookieJar()->clear();

        $authenticator = new SoftAuthenticator();
        $accept = function (string $link) use ($authenticator): void {
            $crawler = $this->client->request('GET', $link);
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[data-passkey-create]');
            $this->client->submit($form->form([
                'password' => 'a long and good passphrase', 'password_repeat' => 'a long and good passphrase',
                'passkey_name' => 'Laptop', 'attestation' => $authenticator->create((string) $form->attr('data-passkey-create')),
            ]));
        };
        $accept($links[0]);
        self::assertResponseIsSuccessful();
        $accept($links[1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This invitation was already used.');
        self::assertCount(1, $this->em->getRepository(Curator::class)->findBy(['email' => 'dave@nextcloud.test']));

        // Malformed and unknown tokens.
        foreach (['/console/invite/not-a-token', '/console/invite/'.str_repeat('a', 64)] as $path) {
            $this->client->request('GET', self::$base.$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testAnOutdatedPasswordHashIsUpgradedAtSignIn(): void
    {
        $bob = $this->curator('bob@nextcloud.test');
        $old = password_hash('correct horse battery staple', \PASSWORD_BCRYPT, ['cost' => 5]);
        $this->em->getConnection()->executeStatement('UPDATE curator SET password_hash = ? WHERE id = ?', [$old, $bob->id]);

        $this->consoleLogin('bob@nextcloud.test');
        self::assertResponseRedirects(self::$base.'/console');
        $hash = (string) $this->em->getConnection()->fetchOne('SELECT password_hash FROM curator WHERE id = ?', [$bob->id]);
        self::assertNotSame($old, $hash);
        self::assertTrue(password_verify('correct horse battery staple', $hash));
    }

    /**
     * An enrolled server (state "applied") is enough for conflicts of interest.
     */
    private function enrolOneServer(): void
    {
        $this->enrolServer('social.example.org');
    }
}
