<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Task;
use App\Entity\TaskAssignee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TaskAssignee>
 */
class TaskAssigneeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskAssignee::class);
    }

    public function findOneByPrincipal(
        Task $task,
        AssigneePrincipalType $type,
        Uuid $principalId,
    ): ?TaskAssignee {
        return $this->findOneBy([
            'task' => $task,
            'principalType' => $type,
            'principalId' => $principalId,
        ]);
    }
}
