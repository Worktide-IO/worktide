<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Autopilot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Autopilot>
 */
class AutopilotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Autopilot::class);
    }
}
