<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WorkspaceInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkspaceInvitation>
 */
class WorkspaceInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceInvitation::class);
    }

    public function findByToken(string $token): ?WorkspaceInvitation
    {
        return $this->findOneBy(['token' => $token]);
    }
}
