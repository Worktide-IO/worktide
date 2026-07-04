<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomerSystem;
use App\Entity\SystemUptimeDay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemUptimeDay>
 */
class SystemUptimeDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemUptimeDay::class);
    }

    public function findOneForDay(CustomerSystem $system, \DateTimeImmutable $day): ?SystemUptimeDay
    {
        return $this->findOneBy(['system' => $system, 'day' => $day]);
    }

    /**
     * Uptime rows for the given systems since $since, oldest first — the caller
     * groups them per system for the sparkline + aggregate.
     *
     * @param list<CustomerSystem> $systems
     * @return list<SystemUptimeDay>
     */
    public function findSince(array $systems, \DateTimeImmutable $since): array
    {
        if ($systems === []) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->andWhere('u.system IN (:systems)')
            ->andWhere('u.day >= :since')
            ->setParameter('systems', $systems)
            ->setParameter('since', $since)
            ->orderBy('u.day', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
