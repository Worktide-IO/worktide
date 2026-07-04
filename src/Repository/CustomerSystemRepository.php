<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\CustomerSystem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerSystem>
 */
class CustomerSystemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerSystem::class);
    }

    /**
     * The systems visible to a customer's portal: all their non-deleted
     * systems, active ones first, then alphabetical. The caller passes the
     * portal contact's OWN customer (authorization happens there).
     *
     * @return list<CustomerSystem>
     */
    public function findVisiblePortalSystems(Customer $customer): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.customer = :customer')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->orderBy('s.isActive', 'DESC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
