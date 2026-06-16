<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tracker;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tracker>
 */
class TrackerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tracker::class);
    }

    public function findDefaultForWorkspace(Workspace $workspace): ?Tracker
    {
        return $this->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
    }
}
