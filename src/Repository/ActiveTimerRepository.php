<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ActiveTimer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveTimer>
 */
class ActiveTimerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveTimer::class);
    }

    public function findForUser(User $user): ?ActiveTimer
    {
        return $this->findOneBy(['user' => $user]);
    }
}
