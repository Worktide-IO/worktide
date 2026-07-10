<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\StaffCalendarConnection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffCalendarConnection>
 */
class StaffCalendarConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffCalendarConnection::class);
    }

    public function findOneByOwner(User $owner): ?StaffCalendarConnection
    {
        return $this->findOneBy(['owner' => $owner]);
    }

    /**
     * Active connections with a feed URL — the sync command's work list.
     *
     * @return list<StaffCalendarConnection>
     */
    public function findActiveConfigured(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.isActive = true')
            ->andWhere("c.icsUrl <> ''")
            ->getQuery()
            ->getResult();
    }
}
