<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Base64Url;
use App\Crypto\Ed25519;
use App\Crypto\SignedEnvelope;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Ed25519Test extends TestCase
{
    /** RFC 8032 section 7.1, test 1 (empty message). */
    private const RFC_SEED = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';
    private const RFC_PUBLIC = 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';
    private const RFC_SIGNATURE = 'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b';

    public function testRfc8032Vector(): void
    {
        [$secret, $public] = Ed25519::keyPairFromSeed((string) hex2bin(self::RFC_SEED));
        self::assertSame(self::RFC_PUBLIC, bin2hex($public));
        self::assertSame(self::RFC_SIGNATURE, bin2hex(Ed25519::sign('', $secret)));
        self::assertTrue(Ed25519::verify((string) hex2bin(self::RFC_SIGNATURE), '', $public));
    }

    public function testVerifyRejectsWrongMessageKeyOrSignature(): void
    {
        [$secret, $public] = TestKeys::random();
        [, $other] = TestKeys::random();
        $signature = Ed25519::sign('message', $secret);

        self::assertTrue(Ed25519::verify($signature, 'message', $public));
        self::assertFalse(Ed25519::verify($signature, 'messagE', $public));
        self::assertFalse(Ed25519::verify($signature, 'message', $other));
        $flipped = $signature;
        $flipped[10] = \chr(\ord($flipped[10]) ^ 0x80);
        self::assertFalse(Ed25519::verify($flipped, 'message', $public));
    }

    #[DataProvider('malformedVerifyInputs')]
    public function testVerifyNeverThrowsOnMalformedInput(string $signature, string $publicKey): void
    {
        self::assertFalse(Ed25519::verify($signature, 'message', $publicKey));
    }

    public static function malformedVerifyInputs(): iterable
    {
        [$secret, $public] = Ed25519::keyPairFromSeed(str_repeat("\x09", 32));
        $sig = Ed25519::sign('message', $secret);
        yield 'empty signature' => ['', $public];
        yield 'short signature' => [substr($sig, 0, 63), $public];
        yield 'long signature' => [$sig."\0", $public];
        yield 'empty key' => [$sig, ''];
        yield 'short key' => [$sig, substr($public, 0, 31)];
        yield 'base64 key instead of raw' => [$sig, Base64Url::encode($public)];
        yield 'all-zero key' => [$sig, str_repeat("\0", 32)];
        yield 'all-zero signature, all-zero key' => [str_repeat("\0", 64), str_repeat("\0", 32)];
        yield 'small-order key (order 4)' => [str_repeat("\0", 64), self::smallOrderPoint()];
        yield 'identity point' => [str_repeat("\0", 64), "\x01".str_repeat("\0", 31)];
    }

    public function testDecodePublicKey(): void
    {
        [, $public] = TestKeys::random();
        self::assertSame($public, Ed25519::decodePublicKey(Base64Url::encode($public)));
    }

    #[DataProvider('invalidEncodedKeys')]
    public function testDecodePublicKeyRejectsWeakAndMalformedKeys(string $encoded): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ed25519::decodePublicKey($encoded);
    }

    public static function invalidEncodedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'all zero' => [Base64Url::encode(str_repeat("\0", 32))];
        yield 'identity point' => [Base64Url::encode("\x01".str_repeat("\0", 31))];
        yield 'small-order point' => [Base64Url::encode(self::smallOrderPoint())];
        yield '31 bytes' => [Base64Url::encode(random_bytes(31))];
        yield '33 bytes' => [Base64Url::encode(random_bytes(33))];
        yield 'standard base64' => [base64_encode(random_bytes(32))];
        yield 'hex' => [bin2hex(random_bytes(32))];
    }

    public function testFingerprintIsBase64UrlSha256OfTheRawKey(): void
    {
        [, $public] = TestKeys::random();
        $fingerprint = Ed25519::fingerprint($public);
        self::assertSame(43, \strlen($fingerprint));
        self::assertSame(hash('sha256', $public, true), Base64Url::decode($fingerprint));
        self::assertNotSame($fingerprint, Ed25519::fingerprint(TestKeys::random()[1]));
    }

    #[DataProvider('badSeeds')]
    public function testKeyPairRejectsBadSeeds(string $seed): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ed25519::keyPairFromSeed($seed);
    }

    public static function badSeeds(): iterable
    {
        yield 'empty' => [''];
        yield '31 bytes' => [str_repeat("\x01", 31)];
        yield '64 bytes' => [str_repeat("\x01", 64)];
    }

    public function testSignRejectsSeedOrPublicKeyAsSecret(): void
    {
        foreach ([str_repeat("\x01", 32), TestKeys::list()[1], ''] as $notASecret) {
            try {
                Ed25519::sign('m', $notASecret);
                self::fail('Signing with a '.\strlen($notASecret).'-byte key was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSignedEnvelopeCarriesCanonicalPayloadAndSignatures(): void
    {
        [$secret, $public] = TestKeys::list();
        $seen = null;
        $result = SignedEnvelope::build(['b' => 1, 'a' => ['y' => 2.0, 'x' => 'é/']], static function (string $payload) use ($secret, &$seen): array {
            $seen = $payload;

            return [['kid' => 'k', 'alg' => 'Ed25519', 'sig' => Base64Url::encode(Ed25519::sign($payload, $secret))]];
        });

        self::assertSame('{"a":{"x":"é/","y":2},"b":1}', $result['payload'], 'RFC 8785: sorted keys, 2.0 written as 2, no escaping of / or é');
        self::assertSame($seen, $result['payload'], 'the signer sees exactly the published bytes');
        self::assertStringEndsWith("}\n", $result['json']);

        $envelope = json_decode($result['json'], true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame(['payload', 'signatures'], array_keys($envelope));
        self::assertSame($result['payload'], Base64Url::decode($envelope['payload']));
        self::assertTrue(Ed25519::verify(Base64Url::decode($envelope['signatures'][0]['sig']), $result['payload'], $public));
    }

    public function testSignedEnvelopeNeedsASignature(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least one signature');
        SignedEnvelope::build(['list' => 'servers'], static fn (string $p): array => []);
    }

    /**
     * A point of order 4 on Curve25519 in Edwards form (y = 0, sign bit clear): ecffffff…7f is
     * p - 1 (order 2), 0000…00 has y = 0 (order 4).
     */
    private static function smallOrderPoint(): string
    {
        return (string) hex2bin('ecffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff7f');
    }
}
