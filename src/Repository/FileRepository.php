<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Enum\FileTarget;
use App\Entity\File;
use App\Entity\Folder;
use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<File>
 */
class FileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, File::class);
    }

    /**
     * Files attached to a task that a portal customer may see — never the ones
     * flagged isHiddenForConnectUsers. The caller must already have authorized
     * the task via {@see \App\Service\Portal\PortalAccessResolver}.
     *
     * @return list<File>
     */
    public function findVisibleForTask(Task $task): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.target = :target')
            ->andWhere('f.targetId = :taskId')
            ->andWhere('f.isHiddenForConnectUsers = false')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('target', FileTarget::Task)
            ->setParameter('taskId', $task->getId(), 'uuid')
            ->orderBy('f.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A single visible task attachment by id, or null — enforces the same
     * task-scoping + hidden gate as {@see findVisibleForTask}.
     */
    public function findVisibleTaskAttachment(Task $task, Uuid $fileId): ?File
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.id = :fileId')
            ->andWhere('f.target = :target')
            ->andWhere('f.targetId = :taskId')
            ->andWhere('f.isHiddenForConnectUsers = false')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('fileId', $fileId, 'uuid')
            ->setParameter('target', FileTarget::Task)
            ->setParameter('taskId', $task->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Portal-visible files of a customer inside a given folder (null = the
     * customer's root). Hard-scoped to target=Customer + the customer's id +
     * not-hidden + not-deleted — the tenant-isolation guarantee for the portal.
     *
     * @return list<File>
     */
    public function findVisibleFilesForCustomer(Customer $customer, ?Folder $folder): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.target = :target')
            ->andWhere('f.targetId = :customerId')
            ->andWhere('f.isHiddenForConnectUsers = false')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('target', FileTarget::Customer)
            ->setParameter('customerId', $customer->getId(), 'uuid')
            ->orderBy('f.name', 'ASC');
        if ($folder === null) {
            $qb->andWhere('f.folder IS NULL');
        } else {
            $qb->andWhere('f.folder = :folder')->setParameter('folder', $folder->getId(), 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * A single portal-visible customer file by id, or null — same hard scope as
     * {@see findVisibleFilesForCustomer}. Used for downloads.
     */
    public function findVisibleCustomerFile(Customer $customer, Uuid $fileId): ?File
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.id = :fileId')
            ->andWhere('f.target = :target')
            ->andWhere('f.targetId = :customerId')
            ->andWhere('f.isHiddenForConnectUsers = false')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('fileId', $fileId, 'uuid')
            ->setParameter('target', FileTarget::Customer)
            ->setParameter('customerId', $customer->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
