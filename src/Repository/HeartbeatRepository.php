<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Heartbeat;
use App\Entity\Server;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Heartbeat>
 */
final class HeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Heartbeat::class);
    }

    /**
     * @return list<Heartbeat> newest first
     */
    public function latestForServer(Server $server, int $limit): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.server = :server')->setParameter('server', $server)
            ->orderBy('h.receivedAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @return list<\DateTimeImmutable> receive times since $since, oldest first
     */
    public function receiveTimesSince(Server $server, \DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('h')
            ->select('h.receivedAt')
            ->where('h.server = :server AND h.receivedAt >= :since')
            ->setParameter('server', $server)->setParameter('since', $since)
            ->orderBy('h.receivedAt', \SortDirection::Ascending)
            ->getQuery()->getArrayResult();

        return array_values(array_map(static fn (array $r): \DateTimeImmutable => $r['receivedAt'], $rows));
    }

    public function countSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.receivedAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
