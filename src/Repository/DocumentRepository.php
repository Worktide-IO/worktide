<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Documents visible in a customer's portal: published, non-hidden,
     * non-private, non-archived documents in the given (already authorized)
     * projects. Most-recently-updated first. The caller passes the contact's
     * allowed projects — this does not itself authorize.
     *
     * @param list<\App\Entity\Project> $projects
     * @return list<Document>
     */
    public function findPublishedForPortalProjects(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->andWhere('d.project IN (:projects)')
            ->andWhere('d.workflowState = :published')
            ->andWhere('d.isHiddenForConnectUsers = false')
            ->andWhere('d.isPrivate = false')
            ->andWhere('d.isArchived = false')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('projects', $projects)
            ->setParameter('published', \App\Entity\Enum\DocumentWorkflowState::Published)
            ->orderBy('d.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
