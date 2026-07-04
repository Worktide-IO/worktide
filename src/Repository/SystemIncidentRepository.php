<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomerSystem;
use App\Entity\SystemIncident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SystemIncident>
 */
class SystemIncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SystemIncident::class);
    }

    public function findOpenOfKind(CustomerSystem $system, \App\Entity\Enum\IncidentKind $kind): ?SystemIncident
    {
        return $this->findOneBy(['system' => $system, 'kind' => $kind, 'resolvedAt' => null]);
    }

    /**
     * Recent incidents/maintenance for the given systems — open first, then
     * newest. Powers "Vorfälle & Wartung".
     *
     * @param list<CustomerSystem> $systems
     * @return list<SystemIncident>
     */
    public function findRecentForSystems(array $systems, int $limit = 20): array
    {
        if ($systems === []) {
            return [];
        }

        return $this->createQueryBuilder('i')
            ->andWhere('i.system IN (:systems)')
            ->setParameter('systems', $systems)
            ->orderBy('i.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
