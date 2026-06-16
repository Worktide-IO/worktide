<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Task;
use App\Entity\Tracker;
use App\Repository\TrackerRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Applies tracker-derived defaults when a Task is created.
 *
 * - If no Tracker is set, pick the workspace's `isDefault=true` Tracker.
 *   (Workspaces without any Tracker keep the field null — the SPA shows
 *   no chip.)
 * - If the Tracker has a `defaultStatus` and the caller didn't supply
 *   one, the Doctrine entity is already required to have a status, so
 *   we only respect tracker.defaultStatus when the API consumer
 *   explicitly passed a tracker AND left status unset on the entity.
 *   Practically the controller always sets a status, so this acts as a
 *   safety net for code paths like importers that build a Task object
 *   directly.
 *
 * Pre-persist (not pre-flush) so the change is part of the same INSERT.
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 50)]
final class TaskTrackerDefaultsListener
{
    public function __construct(
        private readonly TrackerRepository $trackers,
    ) {}

    public function prePersist(PrePersistEventArgs $event): void
    {
        $task = $event->getObject();
        if (!$task instanceof Task) {
            return;
        }
        $workspace = $task->getWorkspace();
        if ($workspace === null) {
            return;
        }

        if ($task->getTracker() === null) {
            $default = $this->trackers->findDefaultForWorkspace($workspace);
            if ($default !== null) {
                $task->setTracker($default);
            }
        }
        // Tracker.defaultStatus is intentionally NOT applied here —
        // Task.status is non-nullable and is set by the caller (API
        // Platform denormalizer, importer, or service). Forcing it
        // would silently override an explicit status set by the user.
    }
}
