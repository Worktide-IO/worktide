<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicForm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicForm>
 */
class PublicFormRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicForm::class);
    }

    /**
     * Resolve a live form by its public slug. Returns null for unknown,
     * disabled, or soft-deleted forms — the public controller turns all
     * three into the same 404 so a slug can't be probed.
     */
    public function findOneEnabledBySlug(string $slug): ?PublicForm
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.slug = :slug')
            ->andWhere('f.isEnabled = true')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
