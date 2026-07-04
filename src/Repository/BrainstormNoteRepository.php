<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrainstormNote;
use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrainstormNote>
 */
class BrainstormNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrainstormNote::class);
    }

    /**
     * The customer's brainstorming board, oldest first (chronological thread).
     *
     * @return list<BrainstormNote>
     */
    public function findForPortalCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
