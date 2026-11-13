<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Curator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Curator>
 */
final class CuratorRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Curator::class);
    }

    public function findOneByEmail(string $email): ?Curator
    {
        return $this->findOneBy(['email' => mb_strtolower($email)]);
    }

    /**
     * @return list<Curator>
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['displayName' => 'ASC']);
    }

    public function countActiveAdmins(): int
    {
        return \count(array_filter($this->findActive(), static fn (Curator $c): bool => $c->isAdmin()));
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Curator) {
            return;
        }
        $user->passwordHash = $newHashedPassword;
        $this->getEntityManager()->flush();
    }
}
