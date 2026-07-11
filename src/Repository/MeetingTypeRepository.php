<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MeetingType;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MeetingType>
 */
class MeetingTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeetingType::class);
    }

    /**
     * Resolve a bookable meeting type by its public slug. A CUSTOM query (not an
     * API Platform operation) so it bypasses the member-only WorkspaceScopeExtension
     * — the anonymous booking page has no session/workspace context; the slug is
     * the credential and the entity carries its own workspace. Disabled / deleted
     * types return null so a slug can't be probed.
     */
    public function findOneEnabledBySlug(string $slug): ?MeetingType
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.slug = :slug')
            ->andWhere('m.isEnabled = true')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Enabled, non-deleted meeting types in a workspace — the bookable list a
     * logged-in portal customer sees. Custom query (bypasses the member-only
     * WorkspaceScopeExtension, which would zero out a ROLE_PORTAL request).
     *
     * @return list<MeetingType>
     */
    public function findAllEnabledForWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.workspace = :ws')
            ->andWhere('m.isEnabled = true')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('ws', $workspace)
            ->orderBy('m.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
