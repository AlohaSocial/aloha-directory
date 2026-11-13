<?php

declare(strict_types=1);

namespace App\Audit;

use App\Crypto\JsonCanonicalizer;
use App\Crypto\ListSigner;
use App\Crypto\ListVerifier;
use App\Crypto\VerificationException;
use App\Crypto\SignedEnvelope;
use App\Entity\AuditHead;
use App\Entity\ListBuild;
use App\Enum\ListName;
use App\Lists\AtomicFileWriter;
use App\Lists\KeySetService;
use App\Service\DirectorySettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Hash chain over the audit log.
 *
 * Entries are written by many processes in their own transactions, so they are not chained when
 * written (that would serialise every audited transaction). Instead seal() runs every minute and
 * chains entries older than a short grace period in id order:
 *
 *     hash(n) = SHA-256( RFC 8785 JSON of {seq, prev: hash(n-1), id, created_at, actor_type,
 *                        actor_id, actor_label, action, server_domain, details} )
 *
 * The chain order is chain_seq, not id: an entry whose transaction committed late is appended at
 * the end, so the chain never has gaps. Once a day the head (seq and hash) is signed with the list
 * key and published (website, Git mirror, audit_head table). Changing, inserting or deleting any
 * sealed entry changes every later hash, and no longer matches the heads that were published.
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    private const GRACE_SECONDS = 30;
    private const BATCH = 2000;
    private const BACKDATE_TOLERANCE_MINUTES = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly ListSigner $signer,
        private readonly DirectorySettings $settings,
        private readonly LockFactory $locks,
        private readonly ClockInterface $clock,
        private readonly KeySetService $keySets,
    ) {
    }

    /**
     * @return int the number of entries sealed
     */
    public function seal(bool $all = false): int
    {
        $lock = $this->locks->createLock('aloha-directory-audit-chain', 300);
        if (!$lock->acquire(true)) {
            return 0;
        }
        try {
            $sealed = 0;
            $cutoff = $all ? $this->clock->now()->modify('+1 day') : $this->clock->now()->modify('-'.self::GRACE_SECONDS.' seconds');
            do {
                $head = $this->head();
                $rows = $this->db->fetchAllAssociative(
                    'SELECT id, created_at, actor_type, actor_id, actor_label, action, server_domain, details FROM audit_log
                     WHERE chain_seq IS NULL AND created_at <= ? ORDER BY id LIMIT '.self::BATCH,
                    [$cutoff->format('Y-m-d H:i:s')],
                );
                if ([] === $rows) {
                    break;
                }
                $this->db->transactional(function (Connection $db) use ($rows, $head, &$sealed): void {
                    [$seq, $prev] = [$head['seq'], $head['hash']];
                    foreach ($rows as $row) {
                        ++$seq;
                        $prev = self::entryHash($seq, $prev, $row);
                        $db->executeStatement('UPDATE audit_log SET chain_seq = ?, chain_hash = ? WHERE id = ? AND chain_seq IS NULL', [$seq, $prev, $row['id']]);
                        ++$sealed;
                    }
                });
            } while (\count($rows) === self::BATCH);

            return $sealed;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{seq: int, hash: string}
     */
    public function head(): array
    {
        $row = $this->db->fetchAssociative('SELECT chain_seq, chain_hash FROM audit_log WHERE chain_seq IS NOT NULL ORDER BY chain_seq DESC LIMIT 1');

        return false === $row ? ['seq' => 0, 'hash' => self::GENESIS] : ['seq' => (int) $row['chain_seq'], 'hash' => (string) $row['chain_hash']];
    }

    /**
     * Entries older than the grace period that are still not sealed (a stuck minute job).
     */
    public function backlog(): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE chain_seq IS NULL AND created_at < ?',
            [$this->clock->now()->modify('-10 minutes')->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Recomputes the whole chain and compares it with the stored hashes and every published head.
     *
     * @return array{ok: bool, entries: int, heads_checked: int, error: ?string}
     */
    public function verify(): array
    {
        // The heads are in the database too, so each one is checked against its signature (list
        // keys from key sets signed by the pinned root keys, which are not in the database) and
        // against the file published on the website.
        $heads = [];
        // Read straight from the table: never trust objects cached by the entity manager here.
        $published = array_map(
            static fn (array $r): AuditHead => new AuditHead((string) $r['day'], (string) $r['seq'], (string) $r['hash'], new \DateTimeImmutable((string) $r['issued_at'], new \DateTimeZone('UTC')), (string) $r['envelope']),
            $this->db->fetchAllAssociative('SELECT day, seq, hash, issued_at, envelope FROM audit_head ORDER BY day'),
        );
        $trusted = [] === $published ? [] : $this->trustedListKeys();
        foreach ($published as $head) {
            $problem = $this->checkHead($head, $trusted);
            if (null !== $problem) {
                return ['ok' => false, 'entries' => 0, 'heads_checked' => 0, 'error' => $problem];
            }
            $heads[(int) $head->seq][] = $head;
        }
        $floor = null;
        $checked = 0;
        $prev = self::GENESIS;
        $expected = 0;
        $after = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, chain_seq, chain_hash, created_at, actor_type, actor_id, actor_label, action, server_domain, details FROM audit_log
                 WHERE chain_seq > ? ORDER BY chain_seq LIMIT '.self::BATCH,
                [$after],
            );
            foreach ($rows as $row) {
                $seq = (int) $row['chain_seq'];
                if ($seq !== ++$expected) {
                    return ['ok' => false, 'entries' => $expected - 1, 'heads_checked' => $checked, 'error' => \sprintf('Entry %d is missing from the chain.', $expected)];
                }
                $prev = self::entryHash($seq, $prev, $row);
                // Entries sealed after a head was published cannot be older than that head: a
                // backdated insert would otherwise blend into the past.
                if (null !== $floor && substr((string) $row['created_at'], 0, 19) < $floor) {
                    return ['ok' => false, 'entries' => $seq - 1, 'heads_checked' => $checked, 'error' => \sprintf('Entry %d (id %s) is dated %s, before an earlier published head: it was backdated.', $seq, $row['id'], $row['created_at'])];
                }
                if (!hash_equals($prev, (string) $row['chain_hash'])) {
                    return ['ok' => false, 'entries' => $seq - 1, 'heads_checked' => $checked, 'error' => \sprintf('Entry %d (id %s) does not match its hash: it was changed, or an earlier entry was.', $seq, $row['id'])];
                }
                foreach ($heads[$seq] ?? [] as $head) {
                    ++$checked;
                    if (!hash_equals($head->hash, $prev)) {
                        return ['ok' => false, 'entries' => $seq, 'heads_checked' => $checked, 'error' => \sprintf('The chain no longer matches the head published on %s.', $head->day)];
                    }
                    $floor = $head->issuedAt->modify('-'.self::BACKDATE_TOLERANCE_MINUTES.' minutes')->format('Y-m-d H:i:s');
                }
                $after = $seq;
            }
        } while (\count($rows) === self::BATCH);

        foreach (array_keys($heads) as $seq) {
            if ($seq > $expected) {
                return ['ok' => false, 'entries' => $expected, 'heads_checked' => $checked, 'error' => \sprintf('A published head names entry %d, but the chain ends at %d: entries were deleted.', $seq, $expected)];
            }
        }

        return ['ok' => true, 'entries' => $expected, 'heads_checked' => $checked, 'error' => null];
    }

    /**
     * @param array<string, string> $trusted
     */
    private function checkHead(AuditHead $head, array $trusted): ?string
    {
        if ([] === $trusted) {
            return 'No list keys signed by the root key are known, so the published heads cannot be checked.';
        }
        try {
            $payload = (new ListVerifier([]))->verifySignedDocument($head->envelope, $trusted);
        } catch (VerificationException $e) {
            return \sprintf('The head of %s has no valid signature (%s): it was changed in the database.', $head->day, $e->getMessage());
        }
        if (($payload['kind'] ?? null) !== 'audit-head' || ($payload['day'] ?? null) !== $head->day
            || ($payload['seq'] ?? null) !== (int) $head->seq || ($payload['hash'] ?? null) !== $head->hash) {
            return \sprintf('The head of %s in the database differs from what was signed.', $head->day);
        }
        $file = $this->headFile($head->day);
        if (is_file($file) && (string) file_get_contents($file) !== $head->envelope) {
            return \sprintf('The head of %s in the database differs from the published file.', $head->day);
        }

        return null;
    }

    /**
     * List keys of every key set ever installed that verifies under the pinned root keys, so heads
     * signed before a key rotation still verify.
     *
     * @return array<string, string>
     */
    private function trustedListKeys(): array
    {
        $verifier = new ListVerifier($this->keySets->rootKeys());
        $keys = [];
        foreach ($this->em->getRepository(ListBuild::class)->findBy(['list' => ListName::Keys->value]) as $build) {
            try {
                $keys += $verifier->verifyKeySet($build->envelope, null, $build->issuedAt)['listKeys'];
            } catch (\Throwable) {
                continue;
            }
        }

        return $keys;
    }

    private function headFile(string $day): string
    {
        return $this->settings->outputDir.'/transparency/audit/'.$day.'.json';
    }

    /**
     * Signs and publishes today's head, once per day.
     */
    public function publishHead(): ?AuditHead
    {
        $now = $this->clock->now();
        $day = $now->format('Y-m-d');
        if (null !== $this->em->find(AuditHead::class, $day)) {
            return null;
        }
        $this->seal();
        $head = $this->head();
        $payload = [
            'kind' => 'audit-head',
            'version' => 1,
            'day' => $day,
            'seq' => $head['seq'],
            'hash' => $head['hash'],
            'issued_at' => $now->format('Y-m-d\TH:i:s\Z'),
        ];
        $envelope = SignedEnvelope::build($payload, $this->signer->sign(...));
        $record = new AuditHead($day, (string) $head['seq'], $head['hash'], $now, $envelope['json']);
        $this->em->persist($record);
        $this->em->flush();

        // Head files are written once and never replaced.
        $file = $this->headFile($day);
        if (is_file($file)) {
            throw new \RuntimeException(\sprintf('%s exists already; audit heads are never overwritten.', $file));
        }
        AtomicFileWriter::write($file, $envelope['json']);
        AtomicFileWriter::write(\dirname($file).'/latest.json', $envelope['json']);

        return $record;
    }

    /**
     * @param array<string, mixed> $row raw audit_log columns
     */
    public static function entryHash(int $seq, string $prev, array $row): string
    {
        $details = json_decode((string) $row['details'], true, 64, \JSON_THROW_ON_ERROR);

        return hash('sha256', JsonCanonicalizer::canonicalize([
            'seq' => $seq,
            'prev' => $prev,
            'id' => (int) $row['id'],
            'created_at' => substr((string) $row['created_at'], 0, 19),
            'actor_type' => (string) $row['actor_type'],
            'actor_id' => null === $row['actor_id'] ? null : (int) $row['actor_id'],
            'actor_label' => (string) $row['actor_label'],
            'action' => (string) $row['action'],
            'server_domain' => null === $row['server_domain'] ? null : (string) $row['server_domain'],
            'details' => $details,
        ]));
    }
}
