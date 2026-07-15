<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LlmUsageLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LlmUsageLog>
 */
class LlmUsageLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LlmUsageLog::class);
    }
}
