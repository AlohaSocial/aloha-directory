<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\RecoveryCodes;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\SoftAuthenticator;

final class ConsoleTest extends FunctionalTestCase
{
    private const BASE = 'https://directory.test-aloha.social';

    public function testLoginNeedsPasswordAndPasskey(): void
    {
        $this->createCurator('alice@nextcloud.test', true);
        $this->createCurator('bob@nextcloud.test');

        // Password without a passkey, a wrong password, someone else's passkey, a phishing site.
        $this->consoleLogin('alice@nextcloud.test', authenticator: new SoftAuthenticator());
        self::assertResponseStatusCodeSame(401);
        $this->consoleLogin('alice@nextcloud.test', 'wrong password');
        self::assertResponseStatusCodeSame(401);
        $this->consoleLogin('alice@nextcloud.test', authenticator: $this->authenticators['bob@nextcloud.test']);
        self::assertResponseStatusCodeSame(401);
        $phished = clone $this->authenticators['alice@nextcloud.test'];
        $phished->origin = 'https://directory.test-aloha.social.evil.example';
        $this->consoleLogin('alice@nextcloud.test', authenticator: $phished);
        self::assertResponseStatusCodeSame(401);
        self::assertSelectorTextContains('.flash-error', 'password or passkey is not correct');

        $this->consoleLogin('alice@nextcloud.test');
        self::assertResponseRedirects(self::BASE.'/console');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Queue');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAssertionCannotBeReplayed(): void
    {
        $this->createCurator('alice@nextcloud.test');
        $crawler = $this->client->request('GET', self::BASE.'/console/login');
        $assertion = $this->authenticators['alice@nextcloud.test']->get((string) $crawler->filter('form')->attr('data-passkey-get'));
        $this->client->submit($crawler->filter('form')->form(['email' => 'alice@nextcloud.test', 'password' => 'correct horse battery staple', 'passkey' => $assertion]));
        self::assertResponseRedirects(self::BASE.'/console');
        $this->logout();

        // The same assertion against a fresh challenge.
        $crawler = $this->client->request('GET', self::BASE.'/console/login');
        $this->client->submit($crawler->filter('form')->form(['email' => 'alice@nextcloud.test', 'password' => 'correct horse battery staple', 'passkey' => $assertion]));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(401);
    }

    public function testRecoveryCodeWorksOnce(): void
    {
        $alice = $this->createCurator('alice@nextcloud.test');
        $codes = static::getContainer()->get(RecoveryCodes::class)->regenerate($alice);
        $this->em->flush();

        $this->consoleLogin('alice@nextcloud.test', recoveryCode: $codes[0]);
        self::assertResponseRedirects(self::BASE.'/console');
        $this->logout();
        $this->consoleLogin('alice@nextcloud.test', recoveryCode: $codes[0]);
        self::assertResponseStatusCodeSame(401);
        // Without the password, a recovery code is not even looked at (and stays usable).
        $this->consoleLogin('alice@nextcloud.test', 'wrong password', recoveryCode: $codes[1]);
        self::assertResponseStatusCodeSame(401);
        self::assertCount(9, $this->curator('alice@nextcloud.test')->recoveryCodes);
    }

    public function testDeactivatedCuratorCannotSignIn(): void
    {
        $curator = $this->createCurator('mallory@nextcloud.test');
        $curator->active = false;
        $this->em->flush();
        $this->consoleLogin('mallory@nextcloud.test');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCuratorPagesNeedTheAdminRole(): void
    {
        $this->createCurator('bob@nextcloud.test');
        $this->consoleLogin('bob@nextcloud.test');
        $this->client->request('GET', self::BASE.'/console/curators');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', self::BASE.'/console/servers');
        self::assertResponseIsSuccessful();
    }

    public function testFormsNeedCsrfTokens(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $this->consoleLogin('alice@nextcloud.test');

        $this->client->request('POST', self::BASE.'/console/servers/social.example.org/pause', ['concern' => 'x', '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(\App\Enum\ServerState::Listed, $this->server('social.example.org')->state);

        // The real form works.
        $crawler = $this->client->request('GET', self::BASE.'/console/servers/social.example.org');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/pause"]')->form(['concern' => 'Investigating a data breach report.']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertSame(\App\Enum\ServerState::Paused, $this->server('social.example.org')->state);
    }

    public function testEveryConsolePageRenders(): void
    {
        $fake = $this->enrolServer('social.example.org');
        $this->listServer($fake);
        $this->installKeySet();
        $this->tick();
        $this->consoleLogin('alice@nextcloud.test');

        foreach (['/console', '/console?tab=applications', '/console?tab=complaints', '/console/servers', '/console/servers/social.example.org',
            '/console/providers', '/console/complaints', '/console/lists', '/console/lists?list=search', '/console/audit', '/console/curators',
            '/console/account', '/console/blocklist'] as $path) {
            $this->client->request('GET', self::BASE.$path);
            self::assertResponseIsSuccessful($path);
        }
    }

    public function testInvitationFlow(): void
    {
        $url = static::getContainer()->get(\App\Security\CuratorAccounts::class)->invite(null, 'new@nextcloud.test', 'New Curator', false);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $crawler = $this->client->request('GET', self::BASE.$path);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[data-passkey-create]');
        $options = json_decode((string) $form->attr('data-passkey-create'), true);
        self::assertSame('directory.test-aloha.social', $options['rp']['id']);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertNotSame('new@nextcloud.test', base64_decode(strtr($options['user']['id'], '-_', '+/')), 'the user handle is random, not the address');

        $authenticator = new SoftAuthenticator();
        $crawler = $this->client->submit($form->form([
            'password' => 'a long and good passphrase', 'password_repeat' => 'a long and good passphrase',
            'passkey_name' => 'Laptop', 'attestation' => $authenticator->create((string) $form->attr('data-passkey-create')),
        ]));
        self::assertResponseIsSuccessful();
        self::assertCount(10, $crawler->filter('ol.codes li'));

        // The link works only once, and the new passkey signs in.
        $this->client->request('GET', self::BASE.$path);
        self::assertResponseStatusCodeSame(404);
        $this->consoleLogin('new@nextcloud.test', 'a long and good passphrase', $authenticator);
        self::assertResponseRedirects(self::BASE.'/console');
    }

    public function testAddingAndRemovingPasskeys(): void
    {
        $this->createCurator('alice@nextcloud.test');
        $this->consoleLogin('alice@nextcloud.test');
        $second = new SoftAuthenticator();

        // A new passkey needs an existing one.
        $crawler = $this->client->request('GET', self::BASE.'/console/account');
        $form = $crawler->filter('form[data-passkey-create]');
        $crawler = $this->client->submit($form->form([
            'name' => 'Security key', 'password' => 'correct horse battery staple', 'passkey' => '',
            'attestation' => $second->create((string) $form->attr('data-passkey-create')),
        ]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-error', 'not correct');
        self::assertCount(1, $crawler->filter('section.card table tbody tr'));

        $form = $crawler->filter('form[data-passkey-create]');
        $this->client->submit($form->form([
            'name' => 'Security key', 'password' => 'correct horse battery staple',
            'passkey' => $this->authenticators['alice@nextcloud.test']->get((string) $form->attr('data-passkey-get')),
            'attestation' => $second->create((string) $form->attr('data-passkey-create')),
        ]));
        self::assertEmailCount(1, null, 'the curator is told about the new passkey');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Security key');
        self::assertCount(2, $crawler->filter('section.card table tbody tr'));

        // Removing one ends the session; the other one still signs in.
        $form = $crawler->filter('form input[name="form"][value="remove_passkey"]')->closest('form');
        self::assertNotNull($form);
        $first = $crawler->filter('select[name="passkey_id"] option')->first()->attr('value');
        $this->client->submit($form->form([
            'passkey_id' => $first, 'password' => 'correct horse battery staple',
            'passkey' => $second->get((string) $form->attr('data-passkey-get')),
        ]));
        self::assertResponseRedirects();
        $this->consoleLogin('alice@nextcloud.test');
        self::assertResponseStatusCodeSame(401);
        $this->consoleLogin('alice@nextcloud.test', authenticator: $second);
        self::assertResponseRedirects(self::BASE.'/console');
    }

    private function logout(): void
    {
        $crawler = $this->client->request('GET', self::BASE.'/console');
        $this->client->submit($crawler->filter('form.logout')->form());
    }

}
