<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Service;
use App\Entity\ServiceVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceVersion>
 */
class ServiceVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceVersion::class);
    }

    /** Highest versionNo currently recorded for a service (0 when none). */
    public function maxVersionNo(Service $service): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COALESCE(MAX(v.versionNo), 0)')
            ->andWhere('v.service = :service')
            ->setParameter('service', $service)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
