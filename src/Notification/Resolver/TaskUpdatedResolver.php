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
 * Notify a ticket's current assignees when the ticket is updated (delivery is
 * batched — see {@see NotificationType::isBatchable()}). New-assignment is
 * covered separately by {@see TaskAssignedResolver}; this one fires on
 * `task.updated` (field changes) so assignees learn about relevant edits. The
 * acting user is never notified about their own edit.
 */
final class TaskUpdatedResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly TaskRepository $tasks,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return $event->getName() === 'task.updated';
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $taskId = $event->getAggregateId();
        $task = $taskId !== null ? $this->tasks->find($taskId) : null;
        if ($task === null) {
            return;
        }

        $label = trim($task->getIdentifier() . ' · ' . $task->getTitle());
        $actorId = $event->getActor()?->getId()?->toRfc4122();

        foreach ($task->getAssignees() as $iri) {
            if (!\is_string($iri)) {
                continue;
            }
            $recipient = $this->recipients->userFromIri($iri);
            if ($recipient === null) {
                continue;
            }
            if ($actorId !== null && $recipient->getId()?->toRfc4122() === $actorId) {
                continue;
            }
            yield new ResolvedNotification(
                recipient: $recipient,
                type: NotificationType::TaskUpdated,
                titleKey: 'notification.task_updated',
                link: '/tasks',
                body: $label,
                titleParams: ['%ticket%' => $task->getIdentifier()],
            );
        }
    }
}
