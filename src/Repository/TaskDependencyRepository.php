<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskDependency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskDependency>
 */
class TaskDependencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskDependency::class);
    }

    /**
     * Returns true if adding a dependency predecessor → successor would
     * close a cycle through the existing dependency graph. Walks the
     * successor's transitive predecessors looking for the predecessor.
     *
     * Type-agnostic for now (any cycle blocks).
     */
    public function wouldCreateCycle(Task $predecessor, Task $successor): bool
    {
        if ($predecessor === $successor || $predecessor->getId()?->equals($successor->getId())) {
            return true;
        }

        $stack = [$predecessor];
        $seen = [];
        $predId = $predecessor->getId()?->toRfc4122();

        while ($stack !== []) {
            $current = array_pop($stack);
            $currentId = $current->getId()?->toRfc4122();
            if ($currentId === null || isset($seen[$currentId])) {
                continue;
            }
            $seen[$currentId] = true;

            $upstream = $this->getEntityManager()->createQueryBuilder()
                ->select('p')
                ->from(Task::class, 'p')
                ->innerJoin(TaskDependency::class, 'd', 'WITH', 'd.predecessor = p AND d.successor = :task')
                ->setParameter('task', $current)
                ->getQuery()
                ->getResult();
            foreach ($upstream as $p) {
                if ($p->getId()?->toRfc4122() === $successor->getId()?->toRfc4122()) {
                    return true;
                }
                $stack[] = $p;
            }
        }
        return false;
    }
}
