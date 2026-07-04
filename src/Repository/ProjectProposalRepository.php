<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectProposal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectProposal>
 */
class ProjectProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectProposal::class);
    }

    /**
     * A customer's non-deleted proposals across the given (already authorized)
     * projects, newest first. Caller passes the contact's allowed projects.
     *
     * @param list<Project> $projects
     * @return list<ProjectProposal>
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
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
