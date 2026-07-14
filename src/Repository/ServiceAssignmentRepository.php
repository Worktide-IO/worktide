<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\ServiceAssignment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceAssignment>
 */
class ServiceAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceAssignment::class);
    }

    /**
     * Assignments visible in a customer's portal: their non-deleted
     * assignments, active/trial first, then by service name. The caller passes
     * the portal contact's OWN customer (authorization happens there).
     *
     * @return list<ServiceAssignment>
     */
    public function findForPortalCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('sv', 's')
            ->join('a.serviceVersion', 'sv')
            ->join('sv.service', 's')
            ->andWhere('a.customer = :customer')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->orderBy('a.status', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
