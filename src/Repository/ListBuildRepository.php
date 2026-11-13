<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ListBuild;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ListBuild>
 */
final class ListBuildRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListBuild::class);
    }

    public function latest(string $list): ?ListBuild
    {
        return $this->findOneBy(['list' => $list], ['serial' => 'DESC']);
    }

    /**
     * Lightweight history rows without the payloads.
     *
     * @return list<array<string, mixed>> rows with id, serial, issuedAt, entryCount, envelopeSha256 and reason
     */
    public function history(string $list, int $limit, int $offset = 0): array
    {
        return array_values($this->createQueryBuilder('b')
            ->select('b.id, b.serial, b.issuedAt, b.entryCount, b.envelopeSha256, b.reason')
            ->where('b.list = :list')->setParameter('list', $list)
            ->orderBy('b.serial', \SortDirection::Descending)
            ->setFirstResult($offset)->setMaxResults($limit)
            ->getQuery()->getArrayResult());
    }
}
