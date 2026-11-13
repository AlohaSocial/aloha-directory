<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Complaint;
use App\Entity\Server;
use App\Enum\ComplaintStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Complaint>
 */
final class ComplaintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Complaint::class);
    }

    /**
     * @return list<Complaint>
     */
    public function findOpen(?Server $server = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('s')->join('c.server', 's')
            ->where('c.status IN (:open)')
            ->setParameter('open', [ComplaintStatus::Open->value, ComplaintStatus::Shared->value])
            ->orderBy('c.createdAt', \SortDirection::Ascending);
        if (null !== $server) {
            $qb->andWhere('c.server = :server')->setParameter('server', $server);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Complaint> complaints the server admin may see (shared, resolved or dismissed after sharing)
     */
    public function findVisibleToAdmin(Server $server): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.server = :server AND c.sharedAt IS NOT NULL')
            ->setParameter('server', $server)
            ->orderBy('c.createdAt', \SortDirection::Descending)
            ->setMaxResults(100)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Complaint>
     */
    public function findRecent(int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('s')->join('c.server', 's')
            ->orderBy('c.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
