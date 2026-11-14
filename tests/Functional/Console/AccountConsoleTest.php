<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Security\RecoveryCodes;
use App\Tests\Support\ConsoleBrowsing;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The curator's own account page: password change, recovery codes and passkey removal. Every change
 * needs the password and a passkey.
 */
final class AccountConsoleTest extends FunctionalTestCase
{
    use ConsoleBrowsing;

    private const PASSWORD = 'correct horse battery staple';
    private const NEW_PASSWORD = 'a much better passphrase';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCurator('alice@nextcloud.test', true);
    }

    public function testPasswordChangeNeedsThePasswordAndAPasskeyAndEndsAllSessions(): void
    {
        $this->signInAs('alice@nextcloud.test');
        $otherDevice = $this->saveSession();
        $this->signInAs('alice@nextcloud.test');

        $cases = [
            'The two new passwords differ.' => [self::PASSWORD, self::NEW_PASSWORD, 'something else entirely', true],
            'The password, passkey or recovery code is not correct.' => ['wrong password', self::NEW_PASSWORD, self::NEW_PASSWORD, true],
            'not correct' => [self::PASSWORD, self::NEW_PASSWORD, self::NEW_PASSWORD, false],
            'The password must have at least 12 characters.' => [self::PASSWORD, 'short', 'short', true],
            'Choose a password that does not contain your e-mail name or repeat one character.' => [self::PASSWORD, 'my name is Alice, hi', 'my name is Alice, hi', true],
            'repeat one character' => [self::PASSWORD, str_repeat('z', 16), str_repeat('z', 16), true],
        ];
        foreach ($cases as $message => [$current, $new, $repeat, $withPasskey]) {
            $this->submitAccountForm('password', ['password' => $current, 'new_password' => $new, 'new_password_repeat' => $repeat], $withPasskey);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main > .flash-error', $message);
        }
        self::assertSame([], $this->audit('curator.password_changed'));
        $version = $this->curator('alice@nextcloud.test')->credentialsVersion;

        $this->submitAccountForm('password', ['password' => self::PASSWORD, 'new_password' => self::NEW_PASSWORD, 'new_password_repeat' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/console/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Password changed. Please sign in again.');
        self::assertCount(1, $this->audit('curator.password_changed'));
        $alice = $this->curator('alice@nextcloud.test');
        self::assertSame($version + 1, $alice->credentialsVersion);
        self::assertNotNull($alice->passwordChangedAt);

        // The other device is signed out; only the new password works.
        $this->restoreSession($otherDevice);
        $this->client->request('GET', self::$base.'/console');
        self::assertResponseRedirects();
        self::assertStringContainsString('/console/login', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->getCookieJar()->clear();
        $this->consoleLogin('alice@nextcloud.test');
        self::assertResponseStatusCodeSame(401);
        $this->consoleLogin('alice@nextcloud.test', self::NEW_PASSWORD);
        self::assertResponseRedirects(self::$base.'/console');
    }

    public function testNewRecoveryCodesNeedAPasskeyNotARecoveryCode(): void
    {
        $alice = $this->curator('alice@nextcloud.test');
        $old = static::getContainer()->get(RecoveryCodes::class)->regenerate($alice);
        $this->em->flush();
        $this->consoleLogin('alice@nextcloud.test');

        // The form offers no recovery code field; posting one anyway does not help.
        $crawler = $this->page('/console/account');
        self::assertSelectorTextContains('main > p', '10 recovery codes left');
        self::assertCount(0, $this->form($crawler, 'recovery')->filter('input[name="recovery_code"]'));
        $this->post('/console/account', ['form' => 'recovery', 'password' => self::PASSWORD, 'recovery_code' => $old[0]]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main > .flash-error', 'not correct');
        self::assertCount(10, $this->curator('alice@nextcloud.test')->recoveryCodes, 'the code was not used up');

        $crawler = $this->submitAccountForm('recovery', ['password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $codes = $crawler->filter('ol.codes li')->each(static fn (Crawler $li): string => $li->text());
        self::assertCount(10, $codes);
        self::assertSame([], array_intersect($codes, $old));
        self::assertCount(1, $this->audit('curator.recovery_codes_regenerated'));

        // Old codes no longer sign in; new ones do.
        $this->client->getCookieJar()->clear();
        $this->consoleLogin('alice@nextcloud.test', recoveryCode: $old[1]);
        self::assertResponseStatusCodeSame(401);
        $this->consoleLogin('alice@nextcloud.test', recoveryCode: $codes[0]);
        self::assertResponseRedirects(self::$base.'/console');
    }

    public function testTheLastPasskeyCannotBeRemoved(): void
    {
        $this->consoleLogin('alice@nextcloud.test');
        $crawler = $this->page('/console/account');
        self::assertCount(0, $crawler->filter('input[name="form"][value="remove_passkey"]'), 'no remove form with a single passkey');
        $id = (string) $this->em->getConnection()->fetchOne('SELECT id FROM curator_passkey');

        $this->post('/console/account', ['form' => 'remove_passkey', 'passkey_id' => $id, 'password' => self::PASSWORD]);
        self::assertSelectorTextContains('main > .flash-error', 'Add another passkey before you remove your last one.');
        $this->post('/console/account', ['form' => 'remove_passkey', 'passkey_id' => '999', 'password' => self::PASSWORD]);
        self::assertSelectorTextContains('main > .flash-error', 'Unknown passkey.');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM curator_passkey'));

        $this->client->request('POST', self::$base.'/console/account', ['form' => 'password', 'password' => self::PASSWORD, '_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPasswordGuessingInAStolenSessionIsLimited(): void
    {
        $this->consoleLogin('alice@nextcloud.test');
        $token = $this->consoleToken();
        for ($i = 0; $i < 20; ++$i) {
            $this->post('/console/account', ['form' => 'recovery', 'password' => 'guess '.$i], $token);
            self::assertSelectorTextContains('main > .flash-error', 'not correct');
        }
        $this->post('/console/account', ['form' => 'recovery', 'password' => 'guess 21'], $token);
        self::assertSelectorTextContains('main > .flash-error', 'Too many attempts. Please wait an hour and try again.');

        // Even the right password and passkey are refused now.
        $this->submitAccountForm('recovery', ['password' => self::PASSWORD]);
        self::assertSelectorTextContains('main > .flash-error', 'Too many attempts.');
        self::assertSame([], $this->audit('curator.recovery_codes_regenerated'));
    }

    private function form(Crawler $crawler, string $name): Crawler
    {
        $form = $crawler->filter('form')->reduce(static fn (Crawler $f): bool => 1 === $f->filter('input[name="form"][value="'.$name.'"]')->count());
        self::assertCount(1, $form, 'No account form '.$name);

        return $form;
    }

    /**
     * Fills in one of the account forms and confirms it with the curator's passkey (unless told not to).
     *
     * @param array<string, string> $values
     */
    private function submitAccountForm(string $name, array $values, bool $withPasskey = true): Crawler
    {
        $form = $this->form($this->page('/console/account'), $name);
        if ($withPasskey) {
            $values['passkey'] = $this->authenticators['alice@nextcloud.test']->get((string) $form->attr('data-passkey-get'));
        }

        return $this->client->submit($form->form(), $values);
    }
}
