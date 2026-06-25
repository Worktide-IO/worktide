<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectStatusUpdate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectStatusUpdate>
 */
class ProjectStatusUpdateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectStatusUpdate::class);
    }
}
