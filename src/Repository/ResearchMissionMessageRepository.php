<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResearchMissionMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResearchMissionMessage>
 */
class ResearchMissionMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResearchMissionMessage::class);
    }
}
