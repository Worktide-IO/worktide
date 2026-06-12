<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Trait\AuditableTrait;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Populates createdByUser / updatedByUser on entities using AuditableTrait
 * from the currently authenticated user.
 *
 * Quietly no-ops when there is no authenticated user (CLI fixtures, internal
 * cron jobs, public endpoints) — the columns then stay null.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AuditableSubscriber
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$this->usesAuditableTrait($entity)) {
                continue;
            }
            $entity->setCreatedByUser($user);
            $entity->setUpdatedByUser($user);
            $em->getClassMetadata($entity::class)
                ->getReflectionClass();
            $uow->recomputeSingleEntityChangeSet(
                $em->getClassMetadata($entity::class),
                $entity,
            );
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->usesAuditableTrait($entity)) {
                continue;
            }
            $entity->setUpdatedByUser($user);
            $uow->recomputeSingleEntityChangeSet(
                $em->getClassMetadata($entity::class),
                $entity,
            );
        }
    }

    private function usesAuditableTrait(object $entity): bool
    {
        $traits = [];
        $class = $entity::class;
        do {
            $traits += class_uses($class) ?: [];
            $class = get_parent_class($class);
        } while ($class !== false);

        return isset($traits[AuditableTrait::class]);
    }
}
