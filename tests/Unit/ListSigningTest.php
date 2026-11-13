<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\Base64Url;
use App\Crypto\Ed25519;
use App\Crypto\ListVerifier;
use App\Crypto\SignedEnvelope;
use App\Crypto\VerificationException;
use App\Lists\KeySetService;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

final class ListSigningTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-13T12:00:00Z');
    }

    public function testFullChainVerifies(): void
    {
        $verifier = $this->verifier();
        $keys = $verifier->verifyKeySet($this->keySet(), null, $this->now);
        self::assertArrayHasKey(TestKeys::LIST_KID, $keys['listKeys']);

        $result = $verifier->verifyList($this->list(['serial' => 10]), $keys['listKeys'], 'servers', 9, $this->now);
        self::assertSame(10, $result['payload']['serial']);
        self::assertFalse($result['expired']);
        self::assertFalse($result['tooOld']);
    }

    public function testReplayedSerialIsRejected(): void
    {
        $keys = $this->verifier()->verifyKeySet($this->keySet(), null, $this->now)['listKeys'];
        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('replay');
        $this->verifier()->verifyList($this->list(['serial' => 10]), $keys, 'servers', 10, $this->now);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $keys = $this->verifier()->verifyKeySet($this->keySet(), null, $this->now)['listKeys'];
        $envelope = json_decode($this->list(['serial' => 10]), true);
        $payload = json_decode(Base64Url::decode($envelope['payload']), true);
        $payload['entries'][] = ['domain' => 'evil.example.net'];
        $envelope['payload'] = Base64Url::encode((string) json_encode($payload));

        $this->expectException(VerificationException::class);
        $this->verifier()->verifyList((string) json_encode($envelope), $keys, 'servers', null, $this->now);
    }

    public function testListSignedByUnknownKeyIsRejected(): void
    {
        $keys = $this->verifier()->verifyKeySet($this->keySet(), null, $this->now)['listKeys'];
        [$secret] = TestKeys::random();
        $json = SignedEnvelope::build($this->payload('servers', 11), static fn (string $p): array => [['kid' => TestKeys::LIST_KID, 'alg' => 'Ed25519', 'sig' => Base64Url::encode(Ed25519::sign($p, $secret))]])['json'];

        $this->expectException(VerificationException::class);
        $this->verifier()->verifyList($json, $keys, 'servers', null, $this->now);
    }

    public function testRevokedListKeyIsNotTrusted(): void
    {
        [, $listPublic] = TestKeys::list();
        $keySet = $this->keySet([['kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode($listPublic), 'status' => 'revoked'], ['kid' => 'other', 'public_key' => TestKeys::encode(TestKeys::random()[1]), 'status' => 'active']]);
        $keys = $this->verifier()->verifyKeySet($keySet, null, $this->now)['listKeys'];
        self::assertArrayNotHasKey(TestKeys::LIST_KID, $keys);

        $this->expectException(VerificationException::class);
        $this->verifier()->verifyList($this->list(['serial' => 12]), $keys, 'servers', null, $this->now);
    }

    public function testKeySetMustBeSignedByRoot(): void
    {
        [$secret] = TestKeys::random();
        [, $listPublic] = TestKeys::list();
        $json = KeySetService::sign([['kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode($listPublic), 'status' => 'active']], 5, TestKeys::ROOT_KID, $secret, $this->now, 365);

        $this->expectException(VerificationException::class);
        $this->verifier()->verifyKeySet($json, null, $this->now);
    }

    public function testListNameCannotBeSwapped(): void
    {
        $keys = $this->verifier()->verifyKeySet($this->keySet(), null, $this->now)['listKeys'];
        $this->expectException(VerificationException::class);
        $this->verifier()->verifyList($this->list(['serial' => 13]), $keys, 'search', null, $this->now);
    }

    public function testExpiryIsReportedNotEnforced(): void
    {
        $keys = $this->verifier()->verifyKeySet($this->keySet(), null, $this->now)['listKeys'];
        $later = $this->now->modify('+40 days');
        $result = $this->verifier()->verifyList($this->list(['serial' => 14]), $keys, 'servers', null, $later);
        self::assertTrue($result['expired']);
        self::assertFalse($result['tooOld']);
        self::assertTrue($this->verifier()->verifyList($this->list(['serial' => 15]), $keys, 'servers', null, $this->now->modify('+100 days'))['tooOld']);
    }

    private function verifier(): ListVerifier
    {
        return new ListVerifier([TestKeys::ROOT_KID => TestKeys::root()[1]]);
    }

    /**
     * @param list<array{kid: string, public_key: string, status: string}>|null $keys
     */
    private function keySet(?array $keys = null): string
    {
        [, $listPublic] = TestKeys::list();
        [$rootSecret] = TestKeys::root();

        return KeySetService::sign($keys ?? [['kid' => TestKeys::LIST_KID, 'public_key' => TestKeys::encode($listPublic), 'status' => 'active']], 1, TestKeys::ROOT_KID, $rootSecret, $this->now, 365);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function list(array $overrides): string
    {
        [$secret] = TestKeys::list();
        $payload = array_merge($this->payload('servers', 1), $overrides);

        return SignedEnvelope::build($payload, static fn (string $p): array => [['kid' => TestKeys::LIST_KID, 'alg' => 'Ed25519', 'sig' => Base64Url::encode(Ed25519::sign($p, $secret))]])['json'];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $list, int $serial): array
    {
        return [
            'list' => $list, 'version' => 1, 'serial' => $serial,
            'issued_at' => $this->now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $this->now->modify('+30 days')->format('Y-m-d\TH:i:s\Z'),
            'entries' => [['domain' => 'social.example.org', 'weight' => 1.0]],
        ];
    }
}
