<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MeetingType;
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
}
