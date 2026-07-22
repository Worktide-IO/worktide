<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Project;
use App\Service\ProjectNumberGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Auto-fills Project.number on insert when the user didn't pass one and
 * the workspace has a projectNumber pattern configured. Manual values
 * always win — pattern is a convenience, not a constraint.
 *
 * Runs synchronously inside the flush, so the resulting number is
 * persisted in the same transaction as the project itself. Sequence
 * gaps from rolled-back inserts are accepted (DE-Steuerrecht is fine
 * with gaps as long as they're explicable; the project log is).
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 0)]
final class ProjectNumberAutoFillSubscriber
{
    public function __construct(
        private readonly ProjectNumberGenerator $generator,
    ) {}

    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof Project) {
            return;
        }
        if ($entity->getNumber() !== null && $entity->getNumber() !== '') {
            return;
        }
        if ($entity->getWorkspace() === null) {
            return; // workspace is validated separately; skip if not set yet
        }

        $generated = $this->generator->generate(
            $entity->getWorkspace(),
            $entity->getCustomer(),
        );
        if ($generated !== null) {
            $entity->setNumber($generated);
        }
    }
}
