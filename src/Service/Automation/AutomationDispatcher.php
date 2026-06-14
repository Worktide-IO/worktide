<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Automation;
use App\Entity\Enum\AutomationTriggerType;
use App\Entity\Task;
use App\Entity\Workflow;
use App\Event\GenericEntityChangedEvent;
use App\Repository\AutomationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Routes captured DomainEvents to matching Automations and runs their
 * actions synchronously.
 *
 * Called from DomainEventEmitterSubscriber.postFlush, AFTER the event log
 * rows are persisted. We guard against re-entry: actions usually mutate
 * entities and so re-fire postFlush — without the guard the dispatcher
 * would queue itself recursively. With the guard, actions can still emit
 * downstream events (chains of automations work) up to a depth limit.
 *
 * Phase-2 MVP: synchronous, in-process. Phase-3 swap: dispatch to
 * Symfony Messenger async queue when scale demands it (just point the
 * trigger event class to a transports config).
 */
#[AsEventListener(event: GenericEntityChangedEvent::class, method: 'dispatch')]
final class AutomationDispatcher
{
    private const MAX_CHAIN_DEPTH = 5;

    private int $depth = 0;

    public function __construct(
        private readonly AutomationRepository $automations,
        private readonly ActionRunner $runner,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatch(GenericEntityChangedEvent $event): void
    {
        if ($this->depth >= self::MAX_CHAIN_DEPTH) {
            $this->logger->warning('Automation chain depth exceeded; refusing to recurse', [
                'event' => $event->getName(),
                'depth' => $this->depth,
            ]);
            return;
        }

        $triggerType = $this->matchTriggerType($event);
        if ($triggerType === null) {
            return;
        }

        $workspace = $event->getWorkspace();
        $aggregateId = $event->getAggregateId();
        if ($workspace === null || $aggregateId === null) {
            return;
        }

        // Only Task events have meaningful per-task automation in the MVP.
        // For Project events we'd need a separate code path — deferred.
        if ($event->getAggregateType() !== 'Task') {
            return;
        }

        $task = $this->em->find(Task::class, $aggregateId);
        if (!$task instanceof Task) {
            return;
        }

        $project = $task->getProject();
        if ($project === null) {
            // Private tasks don't participate in project-scoped automations.
            return;
        }
        $workflow = $project->getWorkflow();
        if ($workflow === null || !$workflow->isEnabled()) {
            return;
        }

        /** @var list<Automation> $candidates */
        $candidates = $this->automations->findBy([
            'workflow' => $workflow,
            'triggerType' => $triggerType,
            'isEnabled' => true,
        ], ['position' => 'ASC']);

        $didRunAnything = false;
        foreach ($candidates as $automation) {
            if (!$this->triggerMatches($automation, $event)) {
                continue;
            }

            $this->depth++;
            try {
                foreach ($automation->getActions() as $action) {
                    $this->runner->run($action, $task);
                    $didRunAnything = true;
                }
            } finally {
                $this->depth--;
            }
        }

        if ($didRunAnything) {
            // Persist the entity mutations the runner just made. This may
            // cascade through Doctrine listeners and re-enter dispatch();
            // the depth counter caps the chain length.
            $this->em->flush();
        }
    }

    /**
     * Maps a raw `<aggregate>.<action>` event name to the AutomationTriggerType
     * the user can subscribe to. Special-case task.status_changed: we synthesise
     * it from any task.updated event whose changeset includes the status field.
     */
    private function matchTriggerType(GenericEntityChangedEvent $event): ?AutomationTriggerType
    {
        $name = $event->getName();
        $payload = $event->getPayload();

        return match ($name) {
            'task.created' => AutomationTriggerType::TaskCreated,
            'task.updated' => $this->resolveTaskUpdated($payload),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTaskUpdated(array $payload): AutomationTriggerType
    {
        if (isset($payload['status'])) {
            return AutomationTriggerType::TaskStatusChanged;
        }
        // assignee is M:N so it doesn't appear in changeset; assignees-changed
        // would need a dedicated event from the set-assignees endpoint.
        if (isset($payload['closedOn'])) {
            return AutomationTriggerType::TaskClosed;
        }
        return AutomationTriggerType::TaskUpdated;
    }

    private function triggerMatches(Automation $automation, GenericEntityChangedEvent $event): bool
    {
        $config = $automation->getTriggerConfig();
        if ($config === []) {
            return true;
        }

        $payload = $event->getPayload();

        // task.status_changed: optionally filter on the new status id
        if ($automation->getTriggerType() === AutomationTriggerType::TaskStatusChanged) {
            $wantTo = $config['toStatusId'] ?? null;
            $wantFrom = $config['fromStatusId'] ?? null;
            $change = $payload['status'] ?? null;
            if (\is_array($change) && isset($change['from'], $change['to'])) {
                if (\is_string($wantTo) && $change['to'] !== $wantTo) {
                    return false;
                }
                if (\is_string($wantFrom) && $change['from'] !== $wantFrom) {
                    return false;
                }
            }
        }

        return true;
    }
}
