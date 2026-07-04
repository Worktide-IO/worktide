<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\FileTarget;
use App\Entity\File;
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
}
