<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Enum\FileTarget;
use App\Entity\Folder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Folder>
 */
final class FolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Folder::class);
    }

    /**
     * Direct child folders of $parent (or the target's root when $parent is null),
     * excluding soft-deleted rows. Used by portal listing + recursive delete.
     *
     * @return list<Folder>
     */
    public function findChildren(Folder $parent): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.parent = :parent')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('parent', $parent)
            ->getQuery()
            ->getResult();
    }

    /**
     * Portal-visible child folders of a customer under $parent (null = root).
     * Hard-scoped to target=Customer + the customer's id + not-hidden +
     * not-deleted — the tenant-isolation guarantee for the portal.
     *
     * @return list<Folder>
     */
    public function findVisibleChildrenForCustomer(Customer $customer, ?Folder $parent): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.target = :target')
            ->andWhere('f.targetId = :customerId')
            ->andWhere('f.isHiddenForConnectUsers = false')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('target', FileTarget::Customer)
            ->setParameter('customerId', $customer->getId(), 'uuid')
            ->orderBy('f.name', 'ASC');
        if ($parent === null) {
            $qb->andWhere('f.parent IS NULL');
        } else {
            $qb->andWhere('f.parent = :parent')->setParameter('parent', $parent->getId(), 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * A single portal-visible customer folder by id, or null — same hard scope
     * as {@see findVisibleChildrenForCustomer}. Used to verify a requested
     * parent/navigation folder belongs to the caller's customer.
     */
    public function findVisibleCustomerFolder(Customer $customer, Uuid $folderId): ?Folder
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.id = :folderId')
            ->andWhere('f.target = :target')
            ->andWhere('f.targetId = :customerId')
            ->andWhere('f.isHiddenForConnectUsers = false')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('folderId', $folderId, 'uuid')
            ->setParameter('target', FileTarget::Customer)
            ->setParameter('customerId', $customer->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
