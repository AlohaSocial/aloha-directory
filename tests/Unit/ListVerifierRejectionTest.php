<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Base64Url;
use App\Crypto\Ed25519;
use App\Crypto\JsonCanonicalizer;
use App\Crypto\ListVerifier;
use App\Crypto\VerificationException;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every way a published list or key set can be wrong must end in a VerificationException (never
 * another exception type, never acceptance). Envelopes are built by hand here, so that malformed
 * ones can be produced.
 */
final class ListVerifierRejectionTest extends TestCase
{
    private const NOW = '2026-11-13T12:00:00Z';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable(self::NOW);
    }

    // --- key set -------------------------------------------------------------------------------

    public function testValidKeySetYieldsOnlyUsableKeys(): void
    {
        [, $a] = TestKeys::random();
        [, $b] = TestKeys::random();
        [, $c] = TestKeys::random();
        [, $d] = TestKeys::random();
        [, $e] = TestKeys::random();
        $result = $this->verifier()->verifyKeySet($this->rootSigned($this->keySetPayload([
            ['alg' => 'Ed25519', 'kid' => 'active', 'public_key' => TestKeys::encode($a), 'status' => 'active'],
            ['alg' => 'Ed25519', 'kid' => 'future-expiry', 'public_key' => TestKeys::encode($b), 'status' => 'active', 'not_after' => '2027-01-01T00:00:00Z'],
            ['alg' => 'Ed25519', 'kid' => 'expired', 'public_key' => TestKeys::encode($c), 'status' => 'active', 'not_after' => '2026-11-13T11:59:59Z'],
            ['alg' => 'Ed25519', 'kid' => 'revoked', 'public_key' => TestKeys::encode($d), 'status' => 'revoked'],
            ['alg' => 'RSA', 'kid' => 'other-alg', 'public_key' => TestKeys::encode($e), 'status' => 'active'],
        ])), 3, $this->now);

        self::assertSame(['active' => $a, 'future-expiry' => $b], $result['listKeys']);
        self::assertSame(4, $result['payload']['serial']);
    }

    public function testKeySetWithOnlyExpiredKeysIsRejected(): void
    {
        [, $a] = TestKeys::random();
        $this->assertRejected('no active list key', fn () => $this->verifier()->verifyKeySet($this->rootSigned($this->keySetPayload([
            ['alg' => 'Ed25519', 'kid' => 'expired', 'public_key' => TestKeys::encode($a), 'status' => 'active', 'not_after' => '2026-11-01T00:00:00Z'],
        ])), null, $this->now));
    }

    public function testKeySetWithOnlyRevokedKeysIsRejected(): void
    {
        [, $a] = TestKeys::random();
        $this->assertRejected('no active list key', fn () => $this->verifier()->verifyKeySet($this->rootSigned($this->keySetPayload([
            ['alg' => 'Ed25519', 'kid' => 'old', 'public_key' => TestKeys::encode($a), 'status' => 'revoked'],
        ])), null, $this->now));
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('badKeySets')]
    public function testMalformedKeySetsAreRejected(array $override, string $message): void
    {
        $payload = array_merge($this->keySetPayload(), $override);
        $this->assertRejected($message, fn () => $this->verifier()->verifyKeySet($this->rootSigned($payload), null, $this->now));
    }

    public static function badKeySets(): iterable
    {
        $zero = Base64Url::encode(str_repeat("\0", 32));
        yield 'no keys' => [['keys' => []], 'no active list key'];
        yield 'keys missing' => [['keys' => null], 'no keys'];
        yield 'keys is an object' => [['keys' => ['a' => ['kid' => 'a', 'public_key' => 'x', 'status' => 'active']]], 'no keys'];
        yield 'entry is a string' => [['keys' => ['abc']], 'Malformed key entry'];
        yield 'entry without kid' => [['keys' => [['public_key' => $zero, 'status' => 'active', 'alg' => 'Ed25519']]], 'Malformed key entry'];
        yield 'numeric kid' => [['keys' => [['kid' => 5, 'public_key' => $zero, 'status' => 'active', 'alg' => 'Ed25519']]], 'Malformed key entry'];
        yield 'weak public key' => [['keys' => [['kid' => 'k', 'public_key' => $zero, 'status' => 'active', 'alg' => 'Ed25519']]], 'invalid public key'];
        yield 'garbage public key' => [['keys' => [['kid' => 'k', 'public_key' => 'not+base64', 'status' => 'active', 'alg' => 'Ed25519']]], 'invalid public key'];
        yield 'list name servers' => [['list' => 'servers'], 'Expected list "keys"'];
    }

    public function testKeySetWithNonStringNotAfterIgnoresTheKey(): void
    {
        [, $a] = TestKeys::random();
        $this->assertRejected('no active list key', fn () => $this->verifier()->verifyKeySet($this->rootSigned($this->keySetPayload([
            ['alg' => 'Ed25519', 'kid' => 'k', 'public_key' => TestKeys::encode($a), 'status' => 'active', 'not_after' => 1893456000],
        ])), null, $this->now));
    }

    public function testKeySetWithUnparsableNotAfterIsAVerificationError(): void
    {
        [, $a] = TestKeys::random();
        $this->assertRejected('', fn () => $this->verifier()->verifyKeySet($this->rootSigned($this->keySetPayload([
            ['alg' => 'Ed25519', 'kid' => 'k', 'public_key' => TestKeys::encode($a), 'status' => 'active', 'not_after' => 'not a date'],
        ])), null, $this->now));
    }

    public function testKeySetSignedByAListKeyIsRejected(): void
    {
        [$listSecret] = TestKeys::list();
        $json = $this->envelope(JsonCanonicalizer::canonicalize($this->keySetPayload()), [$this->sig(TestKeys::ROOT_KID, $listSecret, JsonCanonicalizer::canonicalize($this->keySetPayload()))]);
        $this->assertRejected('No valid signature', fn () => $this->verifier()->verifyKeySet($json, null, $this->now));
    }

    public function testKeySetRollbackIsRejected(): void
    {
        $json = $this->rootSigned($this->keySetPayload(null, 4));
        $this->verifier()->verifyKeySet($json, 3, $this->now);
        $this->assertRejected('replay', fn () => $this->verifier()->verifyKeySet($json, 4, $this->now));
        $this->assertRejected('replay', fn () => $this->verifier()->verifyKeySet($json, 5, $this->now));
    }

    // --- lists ---------------------------------------------------------------------------------

    public function testValidListVerifies(): void
    {
        $result = $this->verifier()->verifyList($this->listSigned($this->listPayload()), $this->listKeys(), 'servers', null, $this->now);
        self::assertSame('servers', $result['payload']['list']);
        self::assertFalse($result['expired']);
        self::assertFalse($result['tooOld']);
    }

    #[DataProvider('serials')]
    public function testSerialMustIncrease(int $serial, ?int $last, bool $accepted): void
    {
        $json = $this->listSigned($this->listPayload(['serial' => $serial]));
        if ($accepted) {
            self::assertSame($serial, $this->verifier()->verifyList($json, $this->listKeys(), 'servers', $last, $this->now)['payload']['serial']);
        } else {
            $this->assertRejected('replay', fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', $last, $this->now));
        }
    }

    public static function serials(): iterable
    {
        yield 'first list' => [1, null, true];
        yield 'next' => [11, 10, true];
        yield 'big jump' => [1794586000, 1794500000, true];
        yield 'same' => [10, 10, false];
        yield 'older' => [9, 10, false];
        yield 'much older' => [1, 1794586000, false];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('badPayloads')]
    public function testBadPayloadsAreRejected(array $override, string $message): void
    {
        $payload = array_merge($this->listPayload(), $override);
        $this->assertRejected($message, fn () => $this->verifier()->verifyList($this->listSigned($payload), $this->listKeys(), 'servers', null, $this->now));
    }

    public static function badPayloads(): iterable
    {
        yield 'list name search' => [['list' => 'search'], 'Expected list "servers"'];
        yield 'list name keys' => [['list' => 'keys'], 'Expected list "servers"'];
        yield 'list name differs in case' => [['list' => 'Servers'], 'Expected list "servers"'];
        yield 'no list name' => [['list' => null], 'Expected list "servers"'];
        yield 'version 2' => [['version' => 2], 'Unsupported list version'];
        yield 'version as string' => [['version' => '1'], 'Unsupported list version'];
        yield 'serial 0' => [['serial' => 0], 'Missing serial'];
        yield 'negative serial' => [['serial' => -5], 'Missing serial'];
        yield 'serial as string' => [['serial' => '12'], 'Missing serial'];
        yield 'serial as float' => [['serial' => 12.5], 'Missing serial'];
        yield 'no serial' => [['serial' => null], 'Missing serial'];
        yield 'no issued_at' => [['issued_at' => null], 'Missing timestamps'];
        yield 'numeric expires_at' => [['expires_at' => 1794586000], 'Missing timestamps'];
        yield 'unparsable issued_at' => [['issued_at' => 'yesterday-ish'], 'Invalid timestamps'];
        yield 'unparsable expires_at' => [['expires_at' => '2026-13-45T99:00:00Z'], 'Invalid timestamps'];
        yield 'issued 6 minutes in the future' => [['issued_at' => '2026-11-13T12:06:00Z'], 'issued in the future'];
    }

    public function testClockSkewOfFiveMinutesIsTolerated(): void
    {
        $result = $this->verifier()->verifyList($this->listSigned($this->listPayload(['issued_at' => '2026-11-13T12:05:00Z'])), $this->listKeys(), 'servers', null, $this->now);
        self::assertSame(1, $result['payload']['version']);
    }

    public function testExpiryAndAgeBoundaries(): void
    {
        $json = $this->listSigned($this->listPayload());
        $at = fn (string $time): array => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, new \DateTimeImmutable($time));

        self::assertFalse($at('2026-12-13T12:00:00Z')['expired'], 'still valid at the exact expiry instant');
        self::assertTrue($at('2026-12-13T12:00:01Z')['expired']);
        self::assertFalse($at('2027-02-11T12:00:00Z')['tooOld'], '90 days exactly');
        self::assertTrue($at('2027-02-11T12:00:01Z')['tooOld']);
    }

    // --- signatures ----------------------------------------------------------------------------

    public function testSignatureByARandomKeyUnderATrustedKidIsRejected(): void
    {
        [$secret] = TestKeys::random();
        $bytes = JsonCanonicalizer::canonicalize($this->listPayload());
        $json = $this->envelope($bytes, [$this->sig(TestKeys::LIST_KID, $secret, $bytes)]);
        $this->assertRejected('No valid signature', fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now));
    }

    public function testValidSignatureUnderAnUnknownKidIsRejected(): void
    {
        [$secret] = TestKeys::list();
        $bytes = JsonCanonicalizer::canonicalize($this->listPayload());
        $json = $this->envelope($bytes, [$this->sig('some-other-kid', $secret, $bytes)]);
        $this->assertRejected('No valid signature', fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now));
    }

    public function testRootKeyCannotSignLists(): void
    {
        [$rootSecret] = TestKeys::root();
        $bytes = JsonCanonicalizer::canonicalize($this->listPayload());
        $json = $this->envelope($bytes, [$this->sig(TestKeys::ROOT_KID, $rootSecret, $bytes)]);
        $this->assertRejected('No valid signature', fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now));
    }

    public function testWrongAlgorithmIsIgnored(): void
    {
        [$secret] = TestKeys::list();
        $bytes = JsonCanonicalizer::canonicalize($this->listPayload());
        $sig = $this->sig(TestKeys::LIST_KID, $secret, $bytes);
        foreach (['ed25519', 'EdDSA', 'none', ''] as $alg) {
            $json = $this->envelope($bytes, [['alg' => $alg] + $sig]);
            $this->assertRejected('No valid signature', fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now));
        }
    }

    public function testOneValidSignatureAmongInvalidOnesIsEnough(): void
    {
        [$secret] = TestKeys::list();
        [$random] = TestKeys::random();
        $bytes = JsonCanonicalizer::canonicalize($this->listPayload());
        $good = $this->sig(TestKeys::LIST_KID, $secret, $bytes);
        $json = $this->envelope($bytes, [
            $this->sig('unknown', $random, $bytes),
            ['sig' => 'not base64!'] + $good,
            $this->sig(TestKeys::LIST_KID, $random, $bytes),
            $good,
        ]);
        self::assertSame('servers', $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now)['payload']['list']);
    }

    public function testSignatureOverOtherBytesIsRejected(): void
    {
        [$secret] = TestKeys::list();
        $canonical = JsonCanonicalizer::canonicalize($this->listPayload());
        // The same document, but pretty-printed: the signature covers exact bytes, not the meaning.
        $pretty = (string) json_encode(json_decode($canonical, true), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        self::assertNotSame($canonical, $pretty);

        $json = $this->envelope($pretty, [$this->sig(TestKeys::LIST_KID, $secret, $canonical)]);
        $this->assertRejected('No valid signature', fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now));
    }

    public function testPayloadSwappedBetweenListsIsRejected(): void
    {
        // A valid signature over a search payload, moved into an envelope for servers.json.
        $search = $this->listSigned($this->listPayload(['list' => 'search']));
        $this->assertRejected('Expected list "servers"', fn () => $this->verifier()->verifyList($search, $this->listKeys(), 'servers', null, $this->now));
    }

    // --- envelope ------------------------------------------------------------------------------

    #[DataProvider('malformedEnvelopes')]
    public function testMalformedEnvelopesAreRejected(string $json, string $message): void
    {
        $this->assertRejected($message, fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now));
        $this->assertRejected($message, fn () => $this->verifier()->verifyKeySet($json, null, $this->now));
    }

    public static function malformedEnvelopes(): iterable
    {
        $payload = Base64Url::encode('{"list":"servers"}');
        $sig = ['kid' => TestKeys::LIST_KID, 'alg' => 'Ed25519', 'sig' => Base64Url::encode(str_repeat("\0", 64))];
        yield 'empty' => ['', 'not valid JSON'];
        yield 'not json' => ['{payload', 'not valid JSON'];
        yield 'json string' => ['"x"', 'Malformed envelope'];
        yield 'json list' => ['[1,2]', 'Malformed envelope'];
        yield 'no payload' => [json_encode(['signatures' => [$sig]]), 'Malformed envelope'];
        yield 'payload is an object' => [json_encode(['payload' => ['list' => 'servers'], 'signatures' => [$sig]]), 'Malformed envelope'];
        yield 'no signatures' => [json_encode(['payload' => $payload]), 'Malformed envelope'];
        yield 'signatures is a string' => [json_encode(['payload' => $payload, 'signatures' => 'x']), 'Malformed envelope'];
        yield 'empty signatures' => [json_encode(['payload' => $payload, 'signatures' => []]), 'Bad signature count'];
        yield 'nine signatures' => [json_encode(['payload' => $payload, 'signatures' => array_fill(0, 9, $sig)]), 'Bad signature count'];
        yield 'signatures keyed by kid' => [json_encode(['payload' => $payload, 'signatures' => ['a' => $sig]]), 'Bad signature count'];
        yield 'standard base64 payload' => [json_encode(['payload' => base64_encode('{"list":"servers"}?>'), 'signatures' => [$sig]]), 'not base64url'];
        yield 'signature entry is a string' => [json_encode(['payload' => $payload, 'signatures' => ['abc']]), 'Malformed signature entry'];
        yield 'signature without sig' => [json_encode(['payload' => $payload, 'signatures' => [['kid' => 'a', 'alg' => 'Ed25519']]]), 'Malformed signature entry'];
        yield 'too deeply nested' => [json_encode(['payload' => $payload, 'signatures' => [$sig], 'x' => [[[[[[[[1]]]]]]]]]), 'not valid JSON'];
        yield 'too large' => ['{"payload":"'.str_repeat('A', 2_000_001).'","signatures":[]}', 'too large'];
    }

    public function testSignedPayloadThatIsNotAnObjectIsRejected(): void
    {
        [$secret] = TestKeys::list();
        foreach (['"servers"', '42', 'null'] as $bytes) {
            $json = $this->envelope($bytes, [$this->sig(TestKeys::LIST_KID, $secret, $bytes)]);
            $this->assertRejected('not an object', fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now));
        }
        $json = $this->envelope('{"list":', [$this->sig(TestKeys::LIST_KID, $secret, '{"list":')]);
        $this->assertRejected('Payload is not valid JSON', fn () => $this->verifier()->verifyList($json, $this->listKeys(), 'servers', null, $this->now));
    }

    public function testVerifySignedDocumentChecksOnlyTheSignature(): void
    {
        [$secret, $public] = TestKeys::list();
        $bytes = '{"kind":"audit-head","seq":5}';
        $json = $this->envelope($bytes, [$this->sig('audit', $secret, $bytes)]);

        self::assertSame(['kind' => 'audit-head', 'seq' => 5], $this->verifier()->verifySignedDocument($json, ['audit' => $public]));
        $this->assertRejected('No valid signature', fn () => $this->verifier()->verifySignedDocument($json, ['audit' => TestKeys::random()[1]]));
        $this->assertRejected('No valid signature', fn () => $this->verifier()->verifySignedDocument($json, []));
    }

    // --- helpers -------------------------------------------------------------------------------

    private function assertRejected(string $message, callable $verify): void
    {
        try {
            $verify();
        } catch (VerificationException $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }
        self::fail('The document was accepted.');
    }

    private function verifier(): ListVerifier
    {
        return new ListVerifier([TestKeys::ROOT_KID => TestKeys::root()[1]]);
    }

    /**
     * @return array<string, string>
     */
    private function listKeys(): array
    {
        return [TestKeys::LIST_KID => TestKeys::list()[1]];
    }

    /**
     * @param list<array<string, mixed>>|null $keys
     *
     * @return array<string, mixed>
     */
    private function keySetPayload(?array $keys = null, int $serial = 4): array
    {
        return [
            'list' => 'keys', 'version' => 1, 'serial' => $serial,
            'issued_at' => self::NOW, 'expires_at' => '2027-11-13T12:00:00Z',
            'keys' => $keys ?? [['alg' => 'Ed25519', 'kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode(TestKeys::list()[1]), 'status' => 'active']],
        ];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function listPayload(array $override = []): array
    {
        return array_merge([
            'list' => 'servers', 'version' => 1, 'serial' => 7,
            'issued_at' => self::NOW, 'expires_at' => '2026-12-13T12:00:00Z',
            'entries' => [['domain' => 'social.example.org', 'weight' => 1]],
        ], $override);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function rootSigned(array $payload): string
    {
        $bytes = JsonCanonicalizer::canonicalize($payload);

        return $this->envelope($bytes, [$this->sig(TestKeys::ROOT_KID, TestKeys::root()[0], $bytes)]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function listSigned(array $payload): string
    {
        $bytes = JsonCanonicalizer::canonicalize($payload);

        return $this->envelope($bytes, [$this->sig(TestKeys::LIST_KID, TestKeys::list()[0], $bytes)]);
    }

    /**
     * @return array{kid: string, alg: string, sig: string}
     */
    private function sig(string $kid, string $secret, string $bytes): array
    {
        return ['kid' => $kid, 'alg' => 'Ed25519', 'sig' => Base64Url::encode(Ed25519::sign($bytes, $secret))];
    }

    /**
     * @param list<array<string, mixed>> $signatures
     */
    private function envelope(string $payloadBytes, array $signatures): string
    {
        return (string) json_encode(['payload' => Base64Url::encode($payloadBytes), 'signatures' => $signatures], \JSON_UNESCAPED_SLASHES);
    }
}
