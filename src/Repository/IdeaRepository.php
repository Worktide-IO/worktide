<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Idea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Idea>
 */
class IdeaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Idea::class);
    }

    /**
     * A customer's non-deleted ideas for the portal, most-upvoted first. The
     * caller passes the portal contact's OWN customer (authorization there).
     *
     * @return list<Idea>
     */
    public function findForPortalCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.customer = :customer')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('customer', $customer)
            ->orderBy('i.voteCount', 'DESC')
            ->addOrderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
