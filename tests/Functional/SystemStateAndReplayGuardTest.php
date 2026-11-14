<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Http\Signature\MessageSignatureVerifier;
use App\Http\Signature\MessageSigner;
use App\Http\Signature\ReplayGuard;
use App\Http\Signature\SignatureException;
use App\Service\DoctrineReplayGuard;
use App\Service\SystemStateStore;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\TestKeys;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class SystemStateAndReplayGuardTest extends FunctionalTestCase
{
    public function testSetCreatesAndUpdatesOneRow(): void
    {
        $store = $this->store();
        self::assertNull($store->get('last_tick'));

        $store->set('last_tick', 'a');
        $this->em->flush();
        $created = $this->clock->now();

        $this->clock->sleep(90);
        $store->set('last_tick', 'b');
        $this->em->flush();
        $this->em->clear();

        $state = $store->get('last_tick');
        self::assertNotNull($state);
        self::assertSame('b', $state->value);
        self::assertSame($created->getTimestamp() + 90, $state->updatedAt->getTimestamp());
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM system_state WHERE name = 'last_tick'"));
    }

    public function testSetIsNotFlushedByItself(): void
    {
        $this->store()->set('x', '1');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM system_state'), 'written with the caller\'s unit of work');
        $this->em->flush();
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM system_state'));
    }

    public function testIncrementIsImmediateAndCountsUp(): void
    {
        $store = $this->store();
        self::assertSame([], $store->counters());

        for ($i = 0; $i < 11; ++$i) {
            $store->increment('list_failure.servers.signature');
        }
        $store->increment('list_failure.search.serial');

        // Written straight to the database, without a flush, and counted numerically (9 + 1 = 10, not "91").
        $counters = $store->counters();
        ksort($counters); // the order of rows differs between databases
        self::assertSame(['list_failure.search.serial' => 1, 'list_failure.servers.signature' => 11], $counters);
        self::assertSame('11', (string) $this->db()->fetchOne("SELECT value FROM system_state WHERE name = 'counter.list_failure.servers.signature'"));
    }

    public function testIncrementUpdatesTheTimestamp(): void
    {
        $store = $this->store();
        $store->increment('c');
        $this->clock->sleep(3600);
        $store->increment('c');
        $this->em->clear();

        self::assertSame($this->clock->now()->getTimestamp(), $store->get(SystemStateStore::COUNTER_PREFIX.'c')?->updatedAt->getTimestamp());
    }

    public function testCountersIgnoreOtherState(): void
    {
        $store = $this->store();
        $store->set('last_tick', '2026-11-13T12:00:00+00:00');
        $store->set('counterfeit', '5');
        $store->set('counter_x', '7');
        $this->em->flush();
        $store->increment('real');

        self::assertSame(['real' => 1], $store->counters());
    }

    public function testCountersHandleLargeValues(): void
    {
        $this->db()->insert('system_state', ['name' => 'counter.big', 'value' => '4294967296', 'updated_at' => '2026-11-13 12:00:00']);
        $this->store()->increment('big');
        self::assertSame(['big' => 4294967297], $this->store()->counters());
    }

    // --- replay guard --------------------------------------------------------------------------

    public function testReplayGuardAcceptsEachFingerprintOnce(): void
    {
        $guard = $this->guard();
        $expires = $this->clock->now()->modify('+10 minutes');

        self::assertTrue($guard->firstSeen(str_repeat('a', 64), $expires));
        self::assertFalse($guard->firstSeen(str_repeat('a', 64), $expires));
        self::assertFalse($guard->firstSeen(str_repeat('a', 64), $expires->modify('+1 day')), 'a later expiry does not reopen it');
        self::assertTrue($guard->firstSeen(str_repeat('b', 64), $expires));

        // A fresh service instance (another worker) sees the same records.
        $other = new DoctrineReplayGuard($this->db());
        self::assertFalse($other->firstSeen(str_repeat('a', 64), $expires));
    }

    public function testReplayGuardIsTheContainerDefault(): void
    {
        self::assertInstanceOf(DoctrineReplayGuard::class, static::getContainer()->get(ReplayGuard::class));
    }

    public function testPurgeRemovesOnlyExpiredFingerprints(): void
    {
        $guard = $this->guard();
        $now = $this->clock->now();
        $guard->firstSeen('old', $now->modify('-1 second'));
        $guard->firstSeen('exact', $now);
        $guard->firstSeen('new', $now->modify('+1 second'));

        self::assertSame(1, $guard->purgeExpired($now));
        self::assertSame(['exact', 'new'], $this->db()->fetchFirstColumn('SELECT hash FROM signature_nonce ORDER BY hash'));
        self::assertFalse($guard->firstSeen('new', $now), 'unexpired fingerprints still block replays');
        self::assertTrue($guard->firstSeen('old', $now->modify('+10 minutes')), 'purged fingerprints are forgotten');
        self::assertSame(0, $guard->purgeExpired($now->modify('-1 day')));
    }

    public function testSignedRequestCannotBeReplayedThroughTheContainerVerifier(): void
    {
        [$secret, $public] = TestKeys::random();
        $path = '/api/v1/servers/social.example.org/status';
        $headers = (new MessageSigner($secret, $public))->sign('GET', 'directory.test-aloha.social', $path, '', $this->clock->now()->getTimestamp());
        $resolve = static fn (string $keyId): ?string => $public;
        $request = static function () use ($headers, $path): Request {
            $server = ['REQUEST_URI' => $path, 'HTTP_HOST' => 'directory.test-aloha.social'];
            foreach ($headers as $name => $value) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }

            return Request::create('https://directory.test-aloha.social'.$path, 'GET', [], [], [], $server);
        };

        $verifier = static::getContainer()->get(MessageSignatureVerifier::class);
        $verifier->verify($request(), '', $resolve);
        try {
            $verifier->verify($request(), '', $resolve);
            self::fail('A replayed request was accepted.');
        } catch (SignatureException $e) {
            self::assertSame('replayed_signature', $e->reason);
        }
        $expires = (string) $this->db()->fetchOne('SELECT expires_at FROM signature_nonce');
        self::assertSame($this->clock->now()->modify('+600 seconds')->format('Y-m-d H:i:s'), substr($expires, 0, 19));
    }

    private function store(): SystemStateStore
    {
        return static::getContainer()->get(SystemStateStore::class);
    }

    private function guard(): DoctrineReplayGuard
    {
        return static::getContainer()->get(DoctrineReplayGuard::class);
    }

    private function db(): Connection
    {
        return $this->em->getConnection();
    }
}
