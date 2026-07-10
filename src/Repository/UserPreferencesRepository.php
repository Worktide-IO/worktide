<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserPreferences;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserPreferences>
 */
class UserPreferencesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPreferences::class);
    }

    public function findOneByUser(User $user): ?UserPreferences
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Every row that carries a notification-preference block — the digest
     * command's candidate set (only users who changed a default can opt into a
     * digest cadence, so this stays small). Filtering by frequency happens in
     * PHP against the JSON.
     *
     * @return list<UserPreferences>
     */
    public function findWithNotificationPreferences(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.notificationPreferences IS NOT NULL')
            ->getQuery()
            ->getResult();
    }
}
