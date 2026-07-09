<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\DomainEventLog;
use App\Entity\Enum\NotificationType;
use App\Notification\NotificationResolverInterface;
use App\Notification\RecipientResolver;
use App\Notification\ResolvedNotification;
use App\Repository\TaskRepository;

/**
 * Notify users assigned to a task at creation time.
 *
 * `task.created` carries `payload.assignedUsers` (a list of user IRIs — added
 * by DomainEventEmitterSubscriber::enrichPayload). Note: assignee CHANGES on an
 * existing task are not yet emitted (a future `task.assignees_changed` event is
 * the intended trigger); v1 covers assignment-at-create.
 */
final class TaskAssignedResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly TaskRepository $tasks,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return $event->getName() === 'task.created';
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $assignees = $event->getPayload()['assignedUsers'] ?? null;
        if (!\is_array($assignees) || $assignees === []) {
            return;
        }

        $taskId = $event->getAggregateId();
        $task = $taskId !== null ? $this->tasks->find($taskId) : null;
        $label = $task !== null
            ? trim($task->getIdentifier() . ' · ' . $task->getTitle())
            : 'eine Aufgabe';

        $actorId = $event->getActor()?->getId()?->toRfc4122();

        foreach ($assignees as $iri) {
            if (!\is_string($iri)) {
                continue;
            }
            $recipient = $this->recipients->userFromIri($iri);
            if ($recipient === null) {
                continue;
            }
            if ($actorId !== null && $recipient->getId()?->toRfc4122() === $actorId) {
                continue; // assigning yourself isn't a notification
            }
            yield new ResolvedNotification(
                recipient: $recipient,
                type: NotificationType::TaskAssigned,
                title: 'Dir wurde eine Aufgabe zugewiesen',
                link: '/tasks',
                body: $label,
            );
        }
    }
}
