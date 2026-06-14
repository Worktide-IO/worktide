<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TimeTrackingSettings;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimeTrackingSettings>
 */
class TimeTrackingSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimeTrackingSettings::class);
    }

    public function findForWorkspace(Workspace $workspace): ?TimeTrackingSettings
    {
        return $this->findOneBy(['workspace' => $workspace]);
    }
}
