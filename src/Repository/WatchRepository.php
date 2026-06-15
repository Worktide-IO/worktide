<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\WatchableTarget;
use App\Entity\User;
use App\Entity\Watch;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Watch>
 */
class WatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Watch::class);
    }

    public function findOneByTuple(
        Workspace $workspace,
        WatchableTarget $target,
        Uuid $targetId,
        User $user,
    ): ?Watch {
        return $this->findOneBy([
            'workspace' => $workspace,
            'target' => $target,
            'targetId' => $targetId,
            'user' => $user,
        ]);
    }
}
