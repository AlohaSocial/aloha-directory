<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Proposal;
use App\Entity\Server;
use App\Enum\ProposalStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Proposal>
 */
final class ProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Proposal::class);
    }

    /**
     * @return list<Proposal>
     */
    public function findOpen(?Server $server = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('s')->join('p.server', 's')
            ->where('p.status = :status')->setParameter('status', ProposalStatus::Open->value)
            ->orderBy('p.proposedAt', \SortDirection::Ascending);
        if (null !== $server) {
            $qb->andWhere('p.server = :server')->setParameter('server', $server);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Proposal>
     */
    public function findForServer(Server $server, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.server = :server')->setParameter('server', $server)
            ->orderBy('p.proposedAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
