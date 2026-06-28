<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialPostTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialPostTarget>
 */
class SocialPostTargetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPostTarget::class);
    }
}
