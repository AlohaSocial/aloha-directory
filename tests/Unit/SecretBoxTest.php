<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Base64Url;
use App\Crypto\SecretBox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    private const KEY = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8';

    public function testRoundTrip(): void
    {
        $box = new SecretBox(self::KEY);
        foreach (['', 'JBSWY3DPEHPK3PXP', str_repeat("\0\xff", 500), "ünïcödé \u{1F600}"] as $plain) {
            $sealed = $box->encrypt($plain, 'totp:1');
            self::assertStringStartsWith('v1.', $sealed);
            if ('' !== $plain) {
                self::assertStringNotContainsString(Base64Url::encode($plain), $sealed);
            }
            self::assertSame($plain, $box->decrypt($sealed, 'totp:1'));
        }
    }

    public function testKeyIsTrimmed(): void
    {
        $sealed = (new SecretBox("  ".self::KEY."\n"))->encrypt('seed', 'ctx');
        self::assertSame('seed', (new SecretBox(self::KEY))->decrypt($sealed, 'ctx'));
    }

    public function testEncryptionIsRandomised(): void
    {
        $box = new SecretBox(self::KEY);
        self::assertNotSame($box->encrypt('same', 'ctx'), $box->encrypt('same', 'ctx'), 'a fresh nonce per message');
    }

    public function testWrongKeyIsRejected(): void
    {
        $sealed = (new SecretBox(self::KEY))->encrypt('seed', 'ctx');
        $other = new SecretBox(Base64Url::encode(str_repeat("\x07", 32)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be decrypted');
        $other->decrypt($sealed, 'ctx');
    }

    public function testWrongContextIsRejected(): void
    {
        $box = new SecretBox(self::KEY);
        $sealed = $box->encrypt('seed', 'totp:curator-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be decrypted');
        $box->decrypt($sealed, 'totp:curator-2');
    }

    public function testEveryFlippedBitIsDetected(): void
    {
        $box = new SecretBox(self::KEY);
        $sealed = $box->encrypt('a secret value', 'ctx');
        $raw = Base64Url::decode(substr($sealed, 3));

        for ($i = 0; $i < \strlen($raw); ++$i) {
            $tampered = $raw;
            $tampered[$i] = \chr(\ord($tampered[$i]) ^ 0x01);
            try {
                $box->decrypt('v1.'.Base64Url::encode($tampered), 'ctx');
                self::fail(\sprintf('A flipped bit at byte %d was not detected.', $i));
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('cannot be decrypted', $e->getMessage());
            }
        }
    }

    public function testTruncatedCiphertextIsRejected(): void
    {
        $box = new SecretBox(self::KEY);
        $raw = Base64Url::decode(substr($box->encrypt('a secret value', 'ctx'), 3));

        $this->expectException(\RuntimeException::class);
        $box->decrypt('v1.'.Base64Url::encode(substr($raw, 0, -1)), 'ctx');
    }

    #[DataProvider('malformedSecrets')]
    public function testMalformedSecretsAreRejected(string $encoded, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new SecretBox(self::KEY))->decrypt($encoded, 'ctx');
    }

    public static function malformedSecrets(): iterable
    {
        yield 'empty' => ['', 'Unknown secret format'];
        yield 'no version' => ['AAAA', 'Unknown secret format'];
        yield 'other version' => ['v2.'.Base64Url::encode(random_bytes(60)), 'Unknown secret format'];
        yield 'upper case version' => ['V1.'.Base64Url::encode(random_bytes(60)), 'Unknown secret format'];
        yield 'nonce only' => ['v1.'.Base64Url::encode(random_bytes(24)), 'too short'];
        yield 'nothing after version' => ['v1.', 'too short'];
    }

    public function testInvalidBase64IsRejectedWithoutLeakingAnotherExceptionType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SecretBox(self::KEY))->decrypt('v1.not+base64/url=', 'ctx');
    }

    #[DataProvider('badKeys')]
    public function testBadKeysAreRefused(string $key): void
    {
        $box = new SecretBox($key);
        foreach ([
            static fn () => $box->encrypt('x', 'ctx'),
            static fn () => $box->mac('x', 'ctx'),
        ] as $call) {
            try {
                $call();
                self::fail('A bad key was accepted.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('DIRECTORY_ENCRYPTION_KEY must be 32 random bytes', $e->getMessage());
            }
        }
    }

    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [Base64Url::encode(random_bytes(31))];
        yield 'too long' => [Base64Url::encode(random_bytes(33))];
        yield 'standard base64 with padding' => [base64_encode(random_bytes(32))];
        yield 'hex' => [bin2hex(random_bytes(32))];
    }

    public function testMacIsKeyedAndContextBound(): void
    {
        $box = new SecretBox(self::KEY);
        $mac = $box->mac('ABCD-EFGH', 'recovery');

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $mac);
        self::assertSame($mac, $box->mac('ABCD-EFGH', 'recovery'), 'deterministic');
        self::assertNotSame($mac, $box->mac('ABCD-EFGH', 'other'));
        self::assertNotSame($mac, $box->mac('ABCD-EFGI', 'recovery'));
        self::assertNotSame($mac, (new SecretBox(Base64Url::encode(str_repeat("\x07", 32))))->mac('ABCD-EFGH', 'recovery'));
        self::assertNotSame(hash('sha256', 'v1|recovery|ABCD-EFGH'), $mac, 'not an unkeyed hash');
    }
}
