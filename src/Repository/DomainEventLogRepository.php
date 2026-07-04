<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DomainEventLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DomainEventLog>
 */
class DomainEventLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DomainEventLog::class);
    }

    /**
     * Most-recent events for a set of aggregate ids of one type, newest first.
     * The portal dashboard passes ONLY visible ticket ids and whitelists event
     * names client-side, so this stays inside the customer's visibility.
     *
     * @param list<Uuid> $ids
     * @return list<DomainEventLog>
     */
    public function findRecentForAggregate(string $aggregateType, array $ids, int $limit = 8): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->andWhere('e.aggregateType = :type')
            ->andWhere('e.aggregateId IN (:ids)')
            ->setParameter('type', $aggregateType)
            ->setParameter('ids', array_map(static fn (Uuid $id) => $id->toBinary(), $ids), ArrayParameterType::BINARY)
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
