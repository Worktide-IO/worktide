<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectShareInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectShareInvitation>
 */
final class ProjectShareInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectShareInvitation::class);
    }

    public function findOneByToken(string $token): ?ProjectShareInvitation
    {
        if ($token === '') {
            return null;
        }

        return $this->findOneBy(['token' => $token]);
    }
}
