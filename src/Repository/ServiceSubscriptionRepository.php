<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\ServiceSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceSubscription>
 */
class ServiceSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceSubscription::class);
    }

    /**
     * Subscriptions visible in a customer's portal: their non-deleted
     * subscriptions, active/trial first, then by name. The caller passes the
     * portal contact's OWN customer (authorization happens there).
     *
     * @return list<ServiceSubscription>
     */
    public function findForPortalCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.customer = :customer')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->orderBy('s.status', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
