<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Crypto\Base64Url;
use App\Crypto\Ed25519;
use App\Crypto\KeyFile;
use App\Crypto\ListVerifier;
use App\Crypto\SecretBox;
use App\Crypto\VerificationException;
use App\Entity\ListBuild;
use App\Lists\KeySetService;
use App\Tests\Support\ConsoleCommandTestCase;
use App\Tests\Support\TestKeys;

/**
 * The key ceremony commands (concept 8.3): app:keys:generate, app:keys:sign-keyset, app:keys:install,
 * and app:secrets:generate-encryption-key.
 */
final class KeyCommandsTest extends ConsoleCommandTestCase
{
    public function testGenerateWritesAPrivateKeyFileAndPrintsItsPublicKey(): void
    {
        $path = $this->scratchFile($this->scratchDir(), 'root.key');

        [$exit, $output] = $this->console('app:keys:generate', ['path' => $path]);
        self::assertSame(0, $exit);
        self::assertFileExists($path);
        self::assertSame(0o600, fileperms($path) & 0o777, 'a key file is readable by its owner only');
        self::assertOutputContains('Key written to '.$path, $output);

        // The printed public key belongs to the seed in the file, and the file is readable by KeyFile.
        [, $public] = Ed25519::keyPairFromSeed(KeyFile::readSeed($path));
        self::assertStringContainsString('Public key (base64url): '.Base64Url::encode($public), $output);
        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}\n\z/', (string) file_get_contents($path)), 'one base64url seed per file');
    }

    public function testGenerateNeverOverwritesAKey(): void
    {
        $path = $this->scratchFile($this->scratchDir(), 'root.key');
        $this->console('app:keys:generate', ['path' => $path]);
        $before = (string) file_get_contents($path);

        try {
            $this->console('app:keys:generate', ['path' => $path]);
            self::fail('An existing key file was overwritten.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Refusing to overwrite', $e->getMessage());
        }
        self::assertSame($before, (string) file_get_contents($path));
    }

    public function testGenerateEncryptionKeyPrintsAFreshUsableKey(): void
    {
        [$exit, $first] = $this->console('app:secrets:generate-encryption-key');
        self::assertSame(0, $exit);
        [, $second] = $this->console('app:secrets:generate-encryption-key');

        self::assertSame(1, preg_match('/\A[A-Za-z0-9_-]{43}\z/', $first), $first);
        self::assertSame(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, \strlen(Base64Url::decode($first)));
        self::assertNotSame($first, $second, 'every run prints a new random key');

        // The printed value works as encryption_key.
        $box = new SecretBox($first);
        self::assertSame('secret', $box->decrypt($box->encrypt('secret', 'test'), 'test'));
        $this->expectException(\RuntimeException::class);
        (new SecretBox($second))->decrypt($box->encrypt('secret', 'test'), 'test');
    }

    public function testSignKeySetValidatesItsOptions(): void
    {
        $dir = $this->scratchDir();
        $root = $this->rootKeyFile($dir);
        $out = $this->scratchFile($dir, 'keys.json');
        [, $listPublic] = TestKeys::list();
        $key = TestKeys::LIST_KID.'='.TestKeys::encode($listPublic);
        $base = ['--root-key' => $root, '--root-kid' => TestKeys::ROOT_KID, '--serial' => '1', '--key' => [$key], '--out' => $out];

        foreach (['--root-key', '--root-kid', '--serial'] as $missing) {
            $input = $base;
            unset($input[$missing]);
            [$exit, $output] = $this->console('app:keys:sign-keyset', $input);
            self::assertSame(2, $exit, $missing);
            self::assertStringContainsString('--root-key, --root-kid and a positive --serial are required.', $output);
        }
        [$exit] = $this->console('app:keys:sign-keyset', ['--serial' => '0'] + $base);
        self::assertSame(2, $exit, 'serial 0 is refused');
        [$exit, $output] = $this->console('app:keys:sign-keyset', ['--key' => []] + $base);
        self::assertSame(2, $exit);
        self::assertStringContainsString('At least one --key is required.', $output);

        foreach ([
            'no-equals-sign',
            TestKeys::LIST_KID.'='.substr(TestKeys::encode($listPublic), 1),         // 42 characters
            TestKeys::LIST_KID.'='.TestKeys::encode($listPublic).':compromised',     // unknown status
            'bad kid!='.TestKeys::encode($listPublic),
        ] as $bad) {
            [$exit, $output] = $this->console('app:keys:sign-keyset', ['--key' => [$key, $bad]] + $base);
            self::assertSame(2, $exit, $bad);
            self::assertStringContainsString('Invalid --key: '.$bad, $output);
        }
        self::assertFileDoesNotExist($out, 'nothing is written when the input is invalid');
    }

    public function testSignKeySetWritesAKeySetThatVerifiesUnderTheRootKey(): void
    {
        $dir = $this->scratchDir();
        $root = $this->rootKeyFile($dir);
        $out = $this->scratchFile($dir, 'keys.json');
        [, $listPublic] = TestKeys::list();
        [, $nextPublic] = TestKeys::random();
        [, $oldPublic] = TestKeys::random();

        [$exit, $output] = $this->console('app:keys:sign-keyset', [
            '--root-key' => $root, '--root-kid' => TestKeys::ROOT_KID, '--serial' => '7', '--out' => $out, '--validity-days' => '30',
            '--key' => [
                TestKeys::LIST_KID.'='.TestKeys::encode($listPublic),
                'test-list-b='.TestKeys::encode($nextPublic).':active',
                'test-list-old='.TestKeys::encode($oldPublic).':revoked',
            ],
        ]);
        self::assertSame(0, $exit, $output);
        self::assertOutputContains('Key set serial 7 written to '.$out, $output);

        $verified = (new ListVerifier([TestKeys::ROOT_KID => TestKeys::root()[1]]))->verifyKeySet((string) file_get_contents($out), 6, new \DateTimeImmutable());
        self::assertSame(7, $verified['payload']['serial']);
        self::assertSame([TestKeys::LIST_KID => $listPublic, 'test-list-b' => $nextPublic], $verified['listKeys'], 'revoked keys are not trusted');
        self::assertSame(['active', 'active', 'revoked'], array_column($verified['payload']['keys'], 'status'));
        $validity = (new \DateTimeImmutable($verified['payload']['expires_at']))->getTimestamp() - (new \DateTimeImmutable($verified['payload']['issued_at']))->getTimestamp();
        self::assertSame(30 * 86400, $validity);

        // Another root key does not sign for the pinned one.
        $this->expectException(VerificationException::class);
        (new ListVerifier([TestKeys::ROOT_KID => TestKeys::random()[1]]))->verifyKeySet((string) file_get_contents($out), null, new \DateTimeImmutable());
    }

    public function testSignKeySetRefusesAWorldReadableRootKey(): void
    {
        $dir = $this->scratchDir();
        $root = $this->rootKeyFile($dir, 0o644);
        $out = $this->scratchFile($dir, 'keys.json');
        [, $listPublic] = TestKeys::list();

        try {
            $this->console('app:keys:sign-keyset', ['--root-key' => $root, '--root-kid' => TestKeys::ROOT_KID, '--serial' => '1', '--key' => [TestKeys::LIST_KID.'='.TestKeys::encode($listPublic)], '--out' => $out]);
            self::fail('A world-readable root key was used.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('must not be world-accessible', $e->getMessage());
        }
        self::assertFileDoesNotExist($out);
    }

    public function testSignKeySetReportsAnUnwritableOutputFile(): void
    {
        $dir = $this->scratchDir();
        $root = $this->rootKeyFile($dir);
        [, $listPublic] = TestKeys::list();

        [$exit, $output] = $this->console('app:keys:sign-keyset', ['--root-key' => $root, '--root-kid' => TestKeys::ROOT_KID, '--serial' => '1', '--key' => [TestKeys::LIST_KID.'='.TestKeys::encode($listPublic)], '--out' => $dir.'/missing-dir/keys.json']);
        self::assertSame(1, $exit);
        self::assertOutputContains('Cannot write '.$dir.'/missing-dir/keys.json', $output);
    }

    public function testInstallPublishesAVerifiedKeySet(): void
    {
        $dir = $this->scratchDir();
        $file = $this->scratchFile($dir, 'keys.json', $this->signedKeySet(3));

        [$exit, $output] = $this->console('app:keys:install', ['file' => $file]);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('keys.json serial 3 is published; active list keys: '.TestKeys::LIST_KID.'.', $output);
        self::assertStringNotContainsString('WARNING', $output, 'the configured signing key is in the set');

        self::assertFileEquals($file, $this->outputDir().'/lists/v1/keys.json');
        $builds = $this->em->getRepository(ListBuild::class)->findBy(['list' => 'keys']);
        self::assertCount(1, $builds);
        self::assertSame('3', $builds[0]->serial);
        self::assertSame('install', $builds[0]->reason);
        self::assertSame([TestKeys::ROOT_KID], $builds[0]->keyIds);
        $audit = $this->auditEntries('keys.installed');
        self::assertCount(1, $audit);
        self::assertSame(['serial' => 3, 'active_list_keys' => [TestKeys::LIST_KID]], $audit[0]->details);
        self::assertNotNull(static::getContainer()->get(KeySetService::class)->installed());
    }

    public function testInstallWarnsWhenTheSigningKeyIsNotInTheKeySet(): void
    {
        [, $otherPublic] = TestKeys::random();
        [, $listPublic] = TestKeys::list();
        $file = $this->scratchFile($this->scratchDir(), 'keys.json', $this->signedKeySet(1, [
            ['kid' => 'test-list-b', 'public_key' => TestKeys::encode($otherPublic), 'status' => 'active'],
            ['kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode($listPublic), 'status' => 'revoked'],
        ]));

        [$exit, $output] = $this->console('app:keys:install', ['file' => $file]);
        self::assertSame(0, $exit);
        self::assertStringContainsString('active list keys: test-list-b.', $output);
        self::assertStringContainsString('The configured signing key "'.TestKeys::LIST_KID.'" is not an active key in this key set', $output);
    }

    public function testInstallRefusesForgedAndReplayedKeySets(): void
    {
        $dir = $this->scratchDir();
        $published = $this->outputDir().'/lists/v1/keys.json';

        // Signed by a root key that is not pinned in the configuration.
        $forged = $this->scratchFile($dir, 'forged.json', $this->signedKeySet(5, null, TestKeys::random()[0]));
        $this->assertInstallFails($forged, 'No valid signature from a trusted key.');
        self::assertFileDoesNotExist($published);

        // A valid key set with one byte of the payload changed.
        $json = json_decode($this->signedKeySet(5), true);
        $payload = json_decode(Base64Url::decode($json['payload']), true);
        $payload['keys'][0]['public_key'] = TestKeys::encode(TestKeys::random()[1]);
        $json['payload'] = Base64Url::encode((string) json_encode($payload));
        $this->assertInstallFails($this->scratchFile($dir, 'tampered.json', (string) json_encode($json)), 'No valid signature from a trusted key.');

        $this->assertInstallFails($this->scratchFile($dir, 'garbage.json', 'not json'), 'Envelope is not valid JSON.');

        // An older or equal serial would roll clients back to keys that were replaced.
        $current = $this->scratchFile($dir, 'keys-5.json', $this->signedKeySet(5));
        self::assertSame(0, $this->console('app:keys:install', ['file' => $current])[0]);
        $this->assertInstallFails($this->scratchFile($dir, 'keys-4.json', $this->signedKeySet(4)), 'replay');
        $this->assertInstallFails($current, 'replay');
        self::assertFileEquals($current, $published);
        self::assertCount(1, $this->em->getRepository(ListBuild::class)->findBy(['list' => 'keys']));
        self::assertCount(1, $this->auditEntries('keys.installed'));

        [$exit, $output] = $this->console('app:keys:install', ['file' => $dir.'/missing.json']);
        self::assertSame(1, $exit);
        self::assertOutputContains('Cannot read '.$dir.'/missing.json', $output);
    }

    /**
     * The whole ceremony with the commands only: a root key, a key set signed offline, installed.
     */
    public function testGeneratedRootKeySignsAKeySetThatInstalls(): void
    {
        $dir = $this->scratchDir();
        $root = $this->rootKeyFile($dir);
        $out = $this->scratchFile($dir, 'keys.json');
        [, $listPublic] = TestKeys::list();

        // The offline machine stamps the key set with its own (wall) clock.
        $this->clockToWallTime();
        self::assertSame(0, $this->console('app:keys:sign-keyset', ['--root-key' => $root, '--root-kid' => TestKeys::ROOT_KID, '--serial' => '2', '--key' => [TestKeys::LIST_KID.'='.TestKeys::encode($listPublic)], '--out' => $out])[0]);
        [$exit, $output] = $this->console('app:keys:install', ['file' => $out]);
        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('keys.json serial 2 is published', $output);

        // A key from app:keys:generate is not pinned, so the directory refuses what it signs.
        $rogue = $this->scratchFile($dir, 'rogue.key');
        $this->console('app:keys:generate', ['path' => $rogue]);
        $rogueOut = $this->scratchFile($dir, 'rogue.json');
        $this->console('app:keys:sign-keyset', ['--root-key' => $rogue, '--root-kid' => TestKeys::ROOT_KID, '--serial' => '9', '--key' => [TestKeys::LIST_KID.'='.TestKeys::encode($listPublic)], '--out' => $rogueOut]);
        $this->assertInstallFails($rogueOut, 'No valid signature');
    }

    private function assertInstallFails(string $file, string $message): void
    {
        try {
            $this->console('app:keys:install', ['file' => $file]);
            self::fail('The key set was installed: '.$file);
        } catch (VerificationException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @param list<array{kid: string, public_key: string, status: string}>|null $keys
     */
    private function signedKeySet(int $serial, ?array $keys = null, ?string $rootSecret = null): string
    {
        [, $listPublic] = TestKeys::list();
        $keys ??= [['kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode($listPublic), 'status' => 'active']];

        return KeySetService::sign($keys, $serial, TestKeys::ROOT_KID, $rootSecret ?? TestKeys::root()[0], $this->clock->now(), 365);
    }

    /**
     * The pinned test root key (seed 0x02 × 32) as a key file.
     */
    private function rootKeyFile(string $dir, int $mode = 0o600): string
    {
        return $this->scratchFile($dir, 'root-'.bin2hex(random_bytes(3)).'.key', Base64Url::encode(str_repeat("\x02", 32))."\n", $mode);
    }
}
