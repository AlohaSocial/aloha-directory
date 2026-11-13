<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Server;
use App\Enum\PauseKind;
use App\Enum\ServerState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Server>
 */
final class ServerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Server::class);
    }

    public function findOneByDomain(string $domain): ?Server
    {
        return $this->findOneBy(['domain' => $domain]);
    }

    /**
     * @return list<Server>
     */
    public function findInStates(ServerState ...$states): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.state IN (:states)')
            ->setParameter('states', array_map(static fn (ServerState $s): string => $s->value, $states))
            ->orderBy('s.domain', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Server>
     */
    public function findActive(): array
    {
        return $this->findInStates(...array_filter(ServerState::cases(), static fn (ServerState $s): bool => $s->isActive()));
    }

    /**
     * @return list<Server> applications waiting for curators, oldest first
     */
    public function findInReview(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.state = :state')->setParameter('state', ServerState::InReview->value)
            ->orderBy('s.inReviewAt', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Server>
     */
    public function findApplied(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.state = :state')->setParameter('state', ServerState::Applied->value)
            ->orderBy('s.appliedAt', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Server> listed servers whose 6-month re-review is due before $before
     */
    public function findReviewDue(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.state IN (:states)')->setParameter('states', [ServerState::Listed->value, ServerState::Paused->value])
            ->andWhere('s.nextReviewAt IS NOT NULL AND s.nextReviewAt <= :before')->setParameter('before', $before)
            ->orderBy('s.nextReviewAt', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Server>
     */
    public function findPaused(?bool $automatic = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.state = :state')->setParameter('state', ServerState::Paused->value)
            ->orderBy('s.pausedAt', \SortDirection::Ascending);
        if (null !== $automatic) {
            $kinds = array_filter(PauseKind::cases(), static fn (PauseKind $k): bool => $k->isAutomatic() === $automatic);
            $qb->andWhere('s.pauseKind IN (:kinds)')->setParameter('kinds', array_map(static fn (PauseKind $k): string => $k->value, array_values($kinds)));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Server>
     */
    public function findWithPendingProfile(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.pendingProfile IS NOT NULL')
            ->orderBy('s.profileUpdatedAt', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Server>
     */
    public function findWithPendingMedia(int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.mediaPending IS NOT NULL')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * @return list<Server> servers that offer search in their profile (for the providers page)
     */
    public function findSearchCandidates(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.searchProvider = true OR s.state IN (:states)')
            ->setParameter('states', [ServerState::Listed->value, ServerState::Paused->value])
            ->orderBy('s.domain', \SortDirection::Ascending)
            ->getQuery()->getResult();
    }

    /**
     * @return array<string, int>
     */
    public function countByState(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.state AS state, COUNT(s.id) AS n')
            ->groupBy('s.state')
            ->getQuery()->getArrayResult();
        $counts = array_fill_keys(array_map(static fn (ServerState $s): string => $s->value, ServerState::cases()), 0);
        foreach ($rows as $row) {
            $state = $row['state'] instanceof ServerState ? $row['state']->value : (string) $row['state'];
            $counts[$state] = (int) $row['n'];
        }

        return $counts;
    }
}
