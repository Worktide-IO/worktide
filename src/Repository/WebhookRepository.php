<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Webhook;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Webhook>
 */
class WebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webhook::class);
    }

    /**
     * @return list<Webhook>
     */
    public function findActiveForWorkspace(?Workspace $workspace): array
    {
        $qb = $this->createQueryBuilder('w')
            ->andWhere('w.isActive = true');
        if ($workspace !== null) {
            $qb->andWhere('w.workspace = :ws')->setParameter('ws', $workspace);
        }
        return $qb->getQuery()->getResult();
    }
}
