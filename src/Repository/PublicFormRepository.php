<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\PublicForm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

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
     * Atomically claim one submission slot: a single conditional UPDATE that
     * increments `submissionCount` iff the form is still under its limit (or has
     * none). Returns true if a slot was claimed, false if the form is full.
     *
     * Race-safe (no read-then-write gap) and does not touch the optimistic-lock
     * version, so concurrent submits at the limit boundary don't collide.
     */
    public function tryClaimSubmissionSlot(PublicForm $form): bool
    {
        $affected = $this->getEntityManager()->createQuery(
            'UPDATE ' . PublicForm::class . ' f'
            . ' SET f.submissionCount = f.submissionCount + 1'
            . ' WHERE f.id = :id AND (f.submissionLimit IS NULL OR f.submissionCount < f.submissionLimit)',
        )->setParameter('id', $form->getId(), UuidType::NAME)->execute();

        return $affected > 0;
    }

    /**
     * Enabled, non-deleted forms whose target project is one the portal
     * contact may see (their customer's external projects). The caller passes
     * the already-authorized projects.
     *
     * @param list<Project> $projects
     * @return list<PublicForm>
     */
    public function findEnabledForPortalProjects(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        return $this->createQueryBuilder('f')
            ->andWhere('f.project IN (:projects)')
            ->andWhere('f.isEnabled = true')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('projects', $projects)
            ->orderBy('f.title', 'ASC')
            ->getQuery()
            ->getResult();
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
