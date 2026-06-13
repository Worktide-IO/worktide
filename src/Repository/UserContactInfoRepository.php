<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserContactInfo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserContactInfo>
 */
class UserContactInfoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserContactInfo::class);
    }
}
