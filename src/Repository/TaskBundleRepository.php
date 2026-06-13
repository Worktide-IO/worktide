<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaskBundle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskBundle>
 */
class TaskBundleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskBundle::class);
    }
}
