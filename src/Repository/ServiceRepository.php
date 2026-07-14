<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Service;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Service>
 */
class ServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Service::class);
    }

    /** Non-deleted service with this exact name in the workspace, if any. */
    public function findOneByName(Workspace $workspace, string $name): ?Service
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.workspace = :ws')
            ->andWhere('s.name = :name')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('ws', $workspace)
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
