<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Newsletter;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Newsletter>
 */
class NewsletterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Newsletter::class);
    }

    /**
     * All (non-deleted) newsletter nodes in a workspace, ordered for tree
     * assembly (parents before deep children isn't guaranteed by position alone,
     * so callers build the tree from the flat list by parent id).
     *
     * @return list<Newsletter>
     */
    public function findAllForWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.workspace = :ws')
            ->andWhere('n.deletedAt IS NULL')
            ->setParameter('ws', $workspace)
            ->orderBy('n.position', 'ASC')
            ->addOrderBy('n.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
