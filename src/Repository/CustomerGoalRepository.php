<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\CustomerGoal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerGoal>
 */
class CustomerGoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerGoal::class);
    }

    /**
     * A customer's non-deleted goals for the portal, in display order. The
     * caller passes the portal contact's OWN customer (authorization there).
     *
     * @return list<CustomerGoal>
     */
    public function findForPortalCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.customer = :customer')
            ->andWhere('g.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->orderBy('g.position', 'ASC')
            ->addOrderBy('g.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
