<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditLogEntry;
use App\Repository\AuditLogRepository;
use App\Tests\Support\FunctionalTestCase;

final class AuditLogRepositoryTest extends FunctionalTestCase
{
    /** @var list<string> ids in insertion order */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ids = [];
        // 12 entries, one minute apart, so that ids cross from one to two digits.
        $rows = [
            ['server.enrolled', 'a.example.org', 'system'],
            ['server.verified', 'a.example.org', 'system'],
            ['server.enrolled', 'b.example.org', 'system'],
            ['curator.login', null, 'alice@nextcloud.test'],
            ['server.listed', 'a.example.org', 'alice@nextcloud.test'],
            ['server.enrolled', 'c.example.org', 'system'],
            ['curator.login', null, 'bob@nextcloud.test'],
            ['server.paused', 'a.example.org', 'bob@nextcloud.test'],
            ['complaint.received', 'b.example.org', 'public'],
            ['server.enrolled', 'd.example.org', 'system'],
            ['server.listed', 'b.example.org', 'bob@nextcloud.test'],
            ['curator.login', null, 'alice@nextcloud.test'],
        ];
        $start = new \DateTimeImmutable('2026-11-13T12:00:00Z');
        $entries = [];
        foreach ($rows as $i => [$action, $domain, $actor]) {
            $entry = new AuditLogEntry($start->modify("+{$i} minutes"), AuditLogEntry::ACTOR_SYSTEM, null, $actor, $action, $domain, ['i' => $i]);
            $this->em->persist($entry);
            $entries[] = $entry;
        }
        $this->em->flush();
        $this->ids = array_map(static fn (AuditLogEntry $e): string => (string) $e->id, $entries);
        $this->em->clear();
    }

    public function testSearchIsNewestFirstAndLimited(): void
    {
        $page = $this->repo()->search(null, null, null, null, 5);
        self::assertSame([11, 10, 9, 8, 7], $this->indexes($page));
    }

    public function testEmptyFiltersMeanNoFilter(): void
    {
        self::assertCount(12, $this->repo()->search('', '', '', '', 50));
        self::assertCount(12, $this->repo()->search(null, null, null, null, 50));
    }

    public function testFiltersCombine(): void
    {
        self::assertSame([9, 5, 2, 0], $this->indexes($this->repo()->search('server.enrolled', null, null, null, 50)));
        self::assertSame([7, 4, 1, 0], $this->indexes($this->repo()->search(null, 'a.example.org', null, null, 50)));
        self::assertSame([11, 4, 3], $this->indexes($this->repo()->search(null, null, 'alice@nextcloud.test', null, 50)));
        self::assertSame([4], $this->indexes($this->repo()->search('server.listed', 'a.example.org', 'alice@nextcloud.test', null, 50)));
        self::assertSame([], $this->repo()->search('server.listed', 'c.example.org', null, null, 50));
    }

    public function testFiltersAreExactNotPatterns(): void
    {
        self::assertSame([], $this->repo()->search('server.%', null, null, null, 50));
        self::assertSame([], $this->repo()->search(null, '%.example.org', null, null, 50));
    }

    public function testKeysetPaginationWalksEveryEntryOnce(): void
    {
        $seen = [];
        $before = null;
        $pages = 0;
        do {
            $page = $this->repo()->search(null, null, null, $before, 5);
            foreach ($page as $entry) {
                $seen[] = (string) $entry->id;
            }
            $before = [] === $page ? null : (string) end($page)->id;
            ++$pages;
        } while ([] !== $page && $pages < 10);

        self::assertSame(array_reverse($this->ids), $seen, 'every entry exactly once, newest first');
        self::assertSame(4, $pages, 'three pages and an empty one');
    }

    public function testBeforeIdIsComparedNumerically(): void
    {
        // Ids 10, 11, 12 are lexically smaller than "9": a string comparison would return them.
        $before = $this->ids[8];
        self::assertSame(array_reverse(\array_slice($this->ids, 0, 8)), array_map(static fn (AuditLogEntry $e): string => (string) $e->id, $this->repo()->search(null, null, null, $before, 50)));
    }

    public function testNonNumericBeforeIdIsIgnored(): void
    {
        foreach (['abc', '5 OR 1=1', '-1', '1.5', ' 5'] as $before) {
            self::assertCount(12, $this->repo()->search(null, null, null, $before, 50), $before);
        }
    }

    public function testDistinctActionsAreSorted(): void
    {
        self::assertSame(['complaint.received', 'curator.login', 'server.enrolled', 'server.listed', 'server.paused', 'server.verified'], $this->repo()->distinctActions());
    }

    public function testCountActionsBetweenIsHalfOpen(): void
    {
        $from = new \DateTimeImmutable('2026-11-13T12:02:00Z');
        $to = new \DateTimeImmutable('2026-11-13T12:07:00Z');
        // Entries 2..6: the start is included, the end (entry 7) is not.
        self::assertEquals(['server.enrolled' => 2, 'curator.login' => 2, 'server.listed' => 1], $this->repo()->countActionsBetween($from, $to));
        self::assertSame([], $this->repo()->countActionsBetween($to, $to));
    }

    public function testFindBetween(): void
    {
        $found = $this->repo()->findBetween('curator.login', new \DateTimeImmutable('2026-11-13T12:03:00Z'), new \DateTimeImmutable('2026-11-13T12:11:00Z'));
        self::assertEqualsCanonicalizing([3, 6], $this->indexes($found), 'entry 11 at 12:11 is outside the half-open range');
        self::assertSame([], $this->repo()->findBetween('server.deleted', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2027-01-01')));
    }

    private function repo(): AuditLogRepository
    {
        return $this->em->getRepository(AuditLogEntry::class);
    }

    /**
     * @param list<AuditLogEntry> $entries
     *
     * @return list<int>
     */
    private function indexes(array $entries): array
    {
        return array_map(static fn (AuditLogEntry $e): int => (int) $e->details['i'], $entries);
    }
}
