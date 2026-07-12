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
 * Notify users assigned to a task — at creation and on later re-assignment.
 *
 *  - `task.created` carries `payload.assignedUsers` (user IRIs added by
 *    DomainEventEmitterSubscriber::enrichPayload) — assignment-at-create.
 *  - `task.assignees_changed` carries `payload.addedUsers` (user IRIs), emitted
 *    by TaskActionsController::setAssignees when the assignee set changes on an
 *    existing task. Only the NEWLY-added users are notified; already-assigned
 *    and removed users are not.
 *
 * The two events produce distinct DomainEventLog ids, so the dispatcher's
 * (recipient, source_event_id, type) dedupe lets a genuine re-assignment
 * notify again without re-firing for unchanged assignees.
 */
final class TaskAssignedResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly TaskRepository $tasks,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return \in_array($event->getName(), ['task.created', 'task.assignees_changed'], true);
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $payload = $event->getPayload();
        $assignees = $event->getName() === 'task.assignees_changed'
            ? ($payload['addedUsers'] ?? null)
            : ($payload['assignedUsers'] ?? null);
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
                titleKey: 'notification.task_assigned',
                link: '/tasks',
                body: $label,
            );
        }
    }
}
