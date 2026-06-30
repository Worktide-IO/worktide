<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomerAgreementRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerAgreementRevision>
 */
class CustomerAgreementRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerAgreementRevision::class);
    }
}
