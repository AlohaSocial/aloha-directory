<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\AuditChain;
use App\Service\Maintenance;
use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\TestKeys;

final class AuditChainTest extends FunctionalTestCase
{
    public function testChainVerifiesAndDetectsTampering(): void
    {
        $this->enrolServer('a.example.org');
        $this->enrolServer('b.example.org');
        $chain = static::getContainer()->get(AuditChain::class);

        // Entries younger than the grace period wait; then they are sealed in order.
        self::assertSame(0, $chain->seal());
        $this->clock->sleep(60);
        $sealed = $chain->seal();
        self::assertGreaterThan(3, $sealed);
        self::assertSame($sealed, $chain->head()['seq']);
        self::assertTrue($chain->verify()['ok']);

        // Changing one detail of an old entry breaks the chain from there on.
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE audit_log SET details = '{\"key_id\":\"forged\"}' WHERE chain_seq = 2");
        $result = $chain->verify();
        self::assertFalse($result['ok']);
        self::assertStringContainsString('Entry 2', (string) $result['error']);
    }

    public function testDeletingEntriesIsDetected(): void
    {
        $this->enrolServer('a.example.org');
        $chain = static::getContainer()->get(AuditChain::class);
        $this->clock->sleep(60);
        $chain->seal();

        $db = $this->em->getConnection();
        $db->executeStatement('DELETE FROM audit_log WHERE chain_seq = 1');
        self::assertStringContainsString('missing', (string) $chain->verify()['error']);
    }

    public function testDeletingTheNewestEntriesIsDetectedByThePublishedHead(): void
    {
        $this->installKeySet();
        $this->enrolServer('a.example.org');
        $this->clock->sleep(60);
        $maintenance = static::getContainer()->get(Maintenance::class);
        $maintenance->run();
        $chain = static::getContainer()->get(AuditChain::class);
        $head = $chain->head();
        self::assertTrue($chain->verify()['ok']);

        // Truncating the tail leaves a consistent chain, but not the one that was published.
        $this->em->getConnection()->executeStatement('DELETE FROM audit_log WHERE chain_seq >= ?', [$head['seq'] - 1]);
        $result = $chain->verify();
        self::assertFalse($result['ok']);
        self::assertStringContainsString('deleted', (string) $result['error']);
    }

    public function testLateEntriesAreAppended(): void
    {
        $this->enrolServer('a.example.org');
        $chain = static::getContainer()->get(AuditChain::class);
        $this->clock->sleep(60);
        $chain->seal();
        $before = $chain->head()['seq'];

        // An entry with a lower id that committed late (simulated by un-sealing an old timestamp row).
        $db = $this->em->getConnection();
        $db->executeStatement(
            "INSERT INTO audit_log (id, created_at, actor_type, actor_id, actor_label, action, server_domain, details) VALUES (100000, ?, 'system', NULL, 'system', 'test.late', NULL, '[]')",
            [$this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s')],
        );
        self::assertSame(1, $chain->seal());
        self::assertSame($before + 1, $chain->head()['seq']);
        self::assertTrue($chain->verify()['ok']);
    }

    public function testDailyHeadIsSignedAndPublished(): void
    {
        $this->installKeySet();
        $this->enrolServer('a.example.org');
        $this->clock->sleep(60);
        static::getContainer()->get(Maintenance::class)->run();

        $dir = static::getContainer()->getParameter('kernel.project_dir').'/var/test-output/transparency/audit/';
        $file = $dir.$this->clock->now()->format('Y-m-d').'.json';
        self::assertFileExists($file);
        self::assertFileEquals($file, $dir.'latest.json');

        $envelope = json_decode((string) file_get_contents($file), true);
        $payload = json_decode((string) base64_decode(strtr($envelope['payload'], '-_', '+/'), true), true);
        self::assertSame('audit-head', $payload['kind']);
        self::assertSame(static::getContainer()->get(AuditChain::class)->head()['hash'], $payload['hash']);

        // The signature verifies with the published list key.
        [, $listPublic] = TestKeys::list();
        $sig = $envelope['signatures'][0];
        self::assertTrue(sodium_crypto_sign_verify_detached(
            (string) base64_decode(strtr($sig['sig'], '-_', '+/'), true),
            (string) base64_decode(strtr($envelope['payload'], '-_', '+/'), true),
            $listPublic,
        ));

        // Once per day.
        self::assertNull(static::getContainer()->get(AuditChain::class)->publishHead());
    }

    public function testForgedHeadsInTheDatabaseAreDetected(): void
    {
        $this->installKeySet();
        $this->enrolServer('a.example.org');
        $this->clock->sleep(60);
        static::getContainer()->get(Maintenance::class)->run();
        $chain = static::getContainer()->get(AuditChain::class);
        self::assertTrue($chain->verify()['ok']);

        // Someone rewrites history and "fixes" the stored head to match: the signature gives it away.
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE audit_head SET hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'");
        $result = $chain->verify();
        self::assertFalse($result['ok']);
        self::assertStringContainsString('differs from what was signed', (string) $result['error']);
    }

    public function testBackdatedEntriesAreDetected(): void
    {
        $this->installKeySet();
        $this->enrolServer('a.example.org');
        $this->clock->sleep(60);
        static::getContainer()->get(Maintenance::class)->run();
        $chain = static::getContainer()->get(AuditChain::class);

        // An entry inserted later but dated last week, to make it look old.
        $this->clock->sleep(3600);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO audit_log (created_at, actor_type, actor_id, actor_label, action, server_domain, details) VALUES (?, 'curator', NULL, 'mallory', 'server.listed', 'a.example.org', '[]')",
            [$this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s')],
        );
        $chain->seal();
        $result = $chain->verify();
        self::assertFalse($result['ok']);
        self::assertStringContainsString('backdated', (string) $result['error']);
    }
}
