<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Task;
use App\Entity\TaskAssignee;
use App\Entity\Workspace;
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

    /**
     * Every user-principal assignment for $userId across the tasks of $workspace.
     * Used when a member leaves, to hand their assigned tasks over to someone
     * still in the workspace.
     *
     * @return list<TaskAssignee>
     */
    public function findUserAssignmentsInWorkspace(Workspace $workspace, Uuid $userId): array
    {
        /** @var list<TaskAssignee> $rows */
        $rows = $this->createQueryBuilder('ta')
            ->join('ta.task', 't')
            ->where('ta.principalType = :type')
            ->andWhere('ta.principalId = :pid')
            ->andWhere('t.workspace = :ws')
            ->setParameter('type', AssigneePrincipalType::User)
            ->setParameter('pid', $userId, 'uuid')
            ->setParameter('ws', $workspace)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
