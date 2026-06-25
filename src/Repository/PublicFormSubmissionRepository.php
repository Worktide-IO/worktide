<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicFormSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicFormSubmission>
 */
class PublicFormSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicFormSubmission::class);
    }
}
