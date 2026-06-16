<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Service\Workflow\WorkflowPolicy;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Stops Task.status updates that would violate the WorkflowTransition
 * policy. Runs at preUpdate so an attempted save fails BEFORE the row
 * is written and before downstream listeners (revisions, mentions,
 * Mercure broadcast) fire.
 *
 * Priority is higher than the revision listener (which runs at 0) so a
 * blocked save doesn't snapshot a half-applied state.
 *
 * The thrown exception is a kernel `AccessDeniedHttpException` so API
 * Platform turns it into a 403 the SPA can show as a toast.
 */
#[AsDoctrineListener(event: Events::preUpdate, priority: 100)]
final class TaskWorkflowEnforcerListener
{
    public function __construct(
        private readonly Security $security,
        private readonly WorkflowPolicy $policy,
    ) {}

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $task = $event->getObject();
        if (!$task instanceof Task) {
            return;
        }
        if (!$event->hasChangedField('status')) {
            return;
        }

        $oldStatus = $event->getOldValue('status');
        $newStatus = $event->getNewValue('status');
        if (!$oldStatus instanceof TaskStatus || !$newStatus instanceof TaskStatus) {
            return;
        }

        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            // No identifiable actor (CLI, fixture) — let it through.
            return;
        }

        $verdict = $this->policy->checkTransition($task, $oldStatus, $newStatus, $actor);
        if ($verdict === true) {
            return;
        }

        throw new AccessDeniedHttpException($verdict);
    }
}
