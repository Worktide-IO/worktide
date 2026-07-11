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

    /**
     * `$root` plus every descendant node (its whole subtree), for a
     * "send to sub-topics too" fan-out. Built in PHP from the flat workspace
     * list — cheap (one query) and avoids a recursive CTE.
     *
     * @return list<Newsletter>
     */
    public function findSubtree(Newsletter $root): array
    {
        $workspace = $root->getWorkspace();
        if ($workspace === null) {
            return [$root];
        }

        $childrenByParent = [];
        foreach ($this->findAllForWorkspace($workspace) as $node) {
            $parentId = $node->getParent()?->getId()?->toRfc4122() ?? '';
            $childrenByParent[$parentId][] = $node;
        }

        $subtree = [];
        $stack = [$root];
        while ($stack !== []) {
            $node = array_pop($stack);
            $subtree[] = $node;
            $id = $node->getId()?->toRfc4122() ?? '';
            foreach ($childrenByParent[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $subtree;
    }
}
