<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomFieldOption;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomFieldOption>
 */
class CustomFieldOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomFieldOption::class);
    }
}
