<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\CustomerSystem;
use App\Entity\Workspace;
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
     * Look up a system by its (externalSource, externalId) pair within a
     * workspace — e.g. the Zabbix host mapping ("zabbix", "<hostid>"). The pair
     * is unique per workspace ({@see \App\Entity\Trait\ExternalReferenceTrait}),
     * so at most one row matches.
     */
    public function findByExternalRef(Workspace $workspace, string $externalSource, string $externalId): ?CustomerSystem
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.workspace = :workspace')
            ->andWhere('s.externalSource = :source')
            ->andWhere('s.externalId = :externalId')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('source', $externalSource)
            ->setParameter('externalId', $externalId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
