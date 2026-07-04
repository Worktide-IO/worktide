<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\SocialPostStatus;
use App\Entity\Project;
use App\Entity\SocialPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialPost>
 */
class SocialPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPost::class);
    }

    /**
     * A customer's non-deleted social posts across the given (already
     * authorized) projects — for the portal Social-Freigabe screen. Newest/
     * soonest first. Caller passes the contact's allowed projects.
     *
     * @param list<Project> $projects
     * @return list<SocialPost>
     */
    public function findForPortalProjects(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.project IN (:projects)')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('projects', $projects)
            ->orderBy('p.scheduledAt', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Posts the publish-due command should fan out now:
     *   - Scheduled with a due (past) scheduledAt, OR
     *   - already Publishing (safety net: a previous pass left queued targets,
     *     e.g. the worker crashed or a target asked for a retry).
     *
     * Limited so a backlog can't starve a single tick.
     *
     * @return list<SocialPost>
     */
    public function findPublishable(\DateTimeImmutable $now, int $limit = 25): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.deletedAt IS NULL')
            ->andWhere('(p.status = :scheduled AND p.scheduledAt <= :now) OR p.status = :publishing')
            ->setParameter('scheduled', SocialPostStatus::Scheduled)
            ->setParameter('publishing', SocialPostStatus::Publishing)
            ->setParameter('now', $now)
            ->orderBy('p.scheduledAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
