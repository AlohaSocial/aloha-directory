<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
final class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /**
     * Keyset pagination, newest first.
     *
     * @return list<AuditLogEntry>
     */
    public function search(?string $action, ?string $domain, ?string $actor, ?string $beforeId, int $limit): array
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.id', \SortDirection::Descending)->setMaxResults($limit);
        if (null !== $action && '' !== $action) {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }
        if (null !== $domain && '' !== $domain) {
            $qb->andWhere('a.serverDomain = :domain')->setParameter('domain', $domain);
        }
        if (null !== $actor && '' !== $actor) {
            $qb->andWhere('a.actorLabel = :actor')->setParameter('actor', $actor);
        }
        if (null !== $beforeId && ctype_digit($beforeId)) {
            $qb->andWhere('a.id < :before')->setParameter('before', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<string>
     */
    public function distinctActions(): array
    {
        return array_column(
            $this->createQueryBuilder('a')->select('DISTINCT a.action')->orderBy('a.action')->getQuery()->getArrayResult(),
            'action',
        );
    }

    /**
     * @return array<string, int>
     */
    public function countActionsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.action AS action, COUNT(a.id) AS n')
            ->where('a.createdAt >= :from AND a.createdAt < :to')
            ->setParameter('from', $from)->setParameter('to', $to)
            ->groupBy('a.action')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r): array => ['a' => $r['action'], 'n' => (int) $r['n']], $rows), 'n', 'a');
    }

    /**
     * @return list<AuditLogEntry>
     */
    public function findBetween(string $action, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.action = :action AND a.createdAt >= :from AND a.createdAt < :to')
            ->setParameter('action', $action)->setParameter('from', $from)->setParameter('to', $to)
            ->getQuery()->getResult();
    }
}
