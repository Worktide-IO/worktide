<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FeedbackSubmission;
use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<FeedbackSubmission>
 */
class FeedbackSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedbackSubmission::class);
    }

    public function findOneByTask(Task $task): ?FeedbackSubmission
    {
        return $this->findOneBy(['task' => $task]);
    }

    /**
     * Load the sidecars for a set of feedback tasks, keyed by task RFC-4122 id.
     * Used by the anonymizer/admin views to resolve attribution in bulk.
     *
     * @param list<Uuid> $taskIds
     * @return array<string, FeedbackSubmission>
     */
    public function mapByTaskIds(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.task', 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $taskIds)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $submission) {
            /** @var FeedbackSubmission $submission */
            $key = $submission->getTask()->getId()?->toRfc4122();
            if ($key !== null) {
                $map[$key] = $submission;
            }
        }

        return $map;
    }
}
