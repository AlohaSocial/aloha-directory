<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Base64Url;
use App\Crypto\Ed25519;
use App\Crypto\KeyFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/aloha-keyfile-test-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @chmod($file, 0o600);
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    public function testGenerateWritesPrivateSeedMatchingThePublicKey(): void
    {
        $path = $this->dir.'/list.key';
        $public = KeyFile::generate($path);

        self::assertSame(Ed25519::PUBLIC_KEY_BYTES, \strlen($public));
        clearstatcache();
        self::assertSame(0o600, fileperms($path) & 0o777, 'only the owner can read the seed');
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\n\z/', (string) file_get_contents($path));

        $seed = KeyFile::readSeed($path);
        [$secret, $derived] = Ed25519::keyPairFromSeed($seed);
        self::assertSame($public, $derived);
        self::assertTrue(Ed25519::verify(Ed25519::sign('msg', $secret), 'msg', $public));
    }

    public function testGenerateProducesDifferentKeys(): void
    {
        self::assertNotSame(KeyFile::generate($this->dir.'/a.key'), KeyFile::generate($this->dir.'/b.key'));
    }

    public function testGenerateRefusesToOverwrite(): void
    {
        $path = $this->dir.'/list.key';
        file_put_contents($path, 'precious');

        try {
            KeyFile::generate($path);
            self::fail('An existing key file was overwritten.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Refusing to overwrite', $e->getMessage());
        }
        self::assertSame('precious', file_get_contents($path));
    }

    public function testGenerateRefusesToFollowADanglingSymlink(): void
    {
        $target = $this->dir.'/elsewhere.key';
        $link = $this->dir.'/list.key';
        symlink($target, $link);

        try {
            KeyFile::generate($link);
            self::fail('A key was written through a symlink.');
        } catch (\Throwable) {
            // file_exists() is false for a dangling link, but fopen(..., 'x') must still refuse it.
        } finally {
            unlink($link);
        }
        self::assertFileDoesNotExist($target);
    }

    public function testGenerateRestoresTheUmask(): void
    {
        $before = umask();
        KeyFile::generate($this->dir.'/list.key');
        self::assertSame($before, umask());
    }

    public function testReadSeedAcceptsSurroundingWhitespace(): void
    {
        $seed = random_bytes(32);
        $path = $this->write("  \n".Base64Url::encode($seed)."\r\n\n", 0o600);
        self::assertSame($seed, KeyFile::readSeed($path));
    }

    #[DataProvider('acceptedModes')]
    public function testReadSeedAcceptsOwnerAndGroupModes(int $mode): void
    {
        $seed = random_bytes(32);
        self::assertSame($seed, KeyFile::readSeed($this->write(Base64Url::encode($seed), $mode)));
    }

    public static function acceptedModes(): iterable
    {
        yield '0600' => [0o600];
        yield '0640' => [0o640];
        yield '0400' => [0o400];
    }

    #[DataProvider('worldAccessibleModes')]
    public function testReadSeedRefusesWorldAccessibleFiles(int $mode): void
    {
        $path = $this->write(Base64Url::encode(random_bytes(32)), $mode);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not be world-accessible');
        KeyFile::readSeed($path);
    }

    public static function worldAccessibleModes(): iterable
    {
        yield '0644 (world readable)' => [0o644];
        yield '0602 (world writable)' => [0o602];
        yield '0601 (world executable)' => [0o601];
        yield '0666' => [0o666];
    }

    public function testReadSeedRefusesAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or unreadable');
        KeyFile::readSeed($this->dir.'/nope.key');
    }

    public function testReadSeedRefusesADirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or unreadable');
        KeyFile::readSeed($this->dir);
    }

    public function testReadSeedRefusesAnUnreadableFile(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root can read any file');
        }
        $path = $this->write(Base64Url::encode(random_bytes(32)), 0o200);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or unreadable');
        KeyFile::readSeed($path);
    }

    #[DataProvider('malformedContents')]
    public function testReadSeedRefusesMalformedContents(string $contents, string $message): void
    {
        $path = $this->write($contents, 0o600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        KeyFile::readSeed($path);
    }

    public static function malformedContents(): iterable
    {
        $seed = str_repeat("\x05", 32);
        yield 'empty' => ['', 'does not contain a 32-byte seed'];
        yield 'standard base64 with padding' => [base64_encode($seed), 'not a base64url Ed25519 seed'];
        yield 'hex (valid base64url alphabet, 48 bytes)' => [bin2hex($seed), 'does not contain a 32-byte seed'];
        yield 'invalid characters' => ['not a seed!', 'not a base64url Ed25519 seed'];
        yield '31 bytes' => [Base64Url::encode(substr($seed, 1)), 'does not contain a 32-byte seed'];
        yield '33 bytes' => [Base64Url::encode($seed."\x05"), 'does not contain a 32-byte seed'];
        yield '64-byte secret key instead of seed' => [Base64Url::encode(Ed25519::keyPairFromSeed($seed)[0]), 'does not contain a 32-byte seed'];
        yield 'two seeds on two lines' => [Base64Url::encode($seed)."\n".Base64Url::encode($seed), 'not a base64url Ed25519 seed'];
        yield 'PEM' => ["-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEIAUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUF\n-----END PRIVATE KEY-----\n", 'not a base64url Ed25519 seed'];
    }

    private function write(string $contents, int $mode): string
    {
        $path = $this->dir.'/k'.bin2hex(random_bytes(4)).'.key';
        file_put_contents($path, $contents);
        chmod($path, $mode);
        clearstatcache();

        return $path;
    }
}
