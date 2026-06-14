<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ChecklistItem;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldOption;
use App\Entity\CustomFieldValue;
use App\Entity\Document;
use App\Entity\DocumentContributor;
use App\Entity\DocumentSpace;
use App\Entity\DomainEventLog;
use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectMilestone;
use App\Entity\ProjectStatus;
use App\Entity\ProjectTemplate;
use App\Entity\ProjectType;
use App\Entity\Workflow;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\TaskBundle;
use App\Entity\TaskDependency;
use App\Entity\TaskList;
use App\Entity\TaskListEntry;
use App\Entity\TaskSchedule;
use App\Entity\TaskStatus;
use App\Entity\TaskTemplate;
use App\Entity\Absence;
use App\Entity\AbsenceRegion;
use App\Entity\Automation;
use App\Entity\AutomationAction;
use App\Entity\Autopilot;
use App\Entity\TaskView;
use App\Entity\Team;
use App\Entity\TypeOfWork;
use App\Entity\UserCapacity;
use App\Entity\UserContactInfo;
use App\Entity\Webhook;
use App\Entity\WorkspaceAbsence;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Event\GenericEntityChangedEvent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Listens for Doctrine entity changes and persists corresponding rows
 * to the domain_events log via a follow-up flush.
 *
 * Trade-off: the event log row lives in a separate transaction from the
 * original entity change (postFlush fires after the first commit). In rare
 * failure modes the change may commit without its event. Acceptable for now;
 * upgrade to a true outbox table (events written in the same SQL transaction)
 * if/when stricter guarantees are required.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class DomainEventEmitterSubscriber
{
    /**
     * Entity FQCN → short aggregate name written into domain_events.name.
     * Add new tracked entities here.
     */
    private const TRACKED = [
        Workspace::class => 'Workspace',
        User::class => 'User',
        WorkspaceMember::class => 'WorkspaceMember',
        Project::class => 'Project',
        ProjectStatus::class => 'ProjectStatus',
        ProjectMember::class => 'ProjectMember',
        Task::class => 'Task',
        TaskStatus::class => 'TaskStatus',
        TimeEntry::class => 'TimeEntry',
        Tag::class => 'Tag',
        CustomFieldDefinition::class => 'CustomFieldDefinition',
        CustomFieldValue::class => 'CustomFieldValue',
        CustomFieldOption::class => 'CustomFieldOption',
        Comment::class => 'Comment',
        ProjectType::class => 'ProjectType',
        TaskList::class => 'TaskList',
        TaskListEntry::class => 'TaskListEntry',
        ChecklistItem::class => 'ChecklistItem',
        TaskDependency::class => 'TaskDependency',
        ProjectMilestone::class => 'ProjectMilestone',
        File::class => 'File',
        FileVersion::class => 'FileVersion',
        ProjectTemplate::class => 'ProjectTemplate',
        TaskBundle::class => 'TaskBundle',
        TaskTemplate::class => 'TaskTemplate',
        Workflow::class => 'Workflow',
        Automation::class => 'Automation',
        AutomationAction::class => 'AutomationAction',
        TaskSchedule::class => 'TaskSchedule',
        TypeOfWork::class => 'TypeOfWork',
        Team::class => 'Team',
        Absence::class => 'Absence',
        WorkspaceAbsence::class => 'WorkspaceAbsence',
        AbsenceRegion::class => 'AbsenceRegion',
        UserCapacity::class => 'UserCapacity',
        UserContactInfo::class => 'UserContactInfo',
        TaskView::class => 'TaskView',
        Autopilot::class => 'Autopilot',
        DocumentSpace::class => 'DocumentSpace',
        Document::class => 'Document',
        DocumentContributor::class => 'DocumentContributor',
        Webhook::class => 'Webhook',
    ];

    /** @var list<GenericEntityChangedEvent> */
    private array $pending = [];

    public function __construct(
        private readonly EventDispatcherInterface $events,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $type = self::TRACKED[$entity::class] ?? null;
            if ($type === null) {
                continue;
            }
            $payload = $this->snapshotInserted($entity, $uow);
            $payload = $this->enrichPayload($entity, GenericEntityChangedEvent::ACTION_CREATED, $payload, []);
            $this->pending[] = new GenericEntityChangedEvent(
                aggregateType: $type,
                aggregateId: $this->extractUuid($entity),
                action: GenericEntityChangedEvent::ACTION_CREATED,
                payload: $payload,
                workspace: $this->extractWorkspace($entity),
            );
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $type = self::TRACKED[$entity::class] ?? null;
            if ($type === null) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            if ($changeSet === []) {
                continue;
            }
            $payload = $this->normaliseChangeSet($changeSet);
            $payload = $this->enrichPayload($entity, GenericEntityChangedEvent::ACTION_UPDATED, $payload, $changeSet);
            $this->pending[] = new GenericEntityChangedEvent(
                aggregateType: $type,
                aggregateId: $this->extractUuid($entity),
                action: GenericEntityChangedEvent::ACTION_UPDATED,
                payload: $payload,
                workspace: $this->extractWorkspace($entity),
            );
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $type = self::TRACKED[$entity::class] ?? null;
            if ($type === null) {
                continue;
            }
            $this->pending[] = new GenericEntityChangedEvent(
                aggregateType: $type,
                aggregateId: $this->extractUuid($entity),
                action: GenericEntityChangedEvent::ACTION_DELETED,
                payload: [],
                workspace: $this->extractWorkspace($entity),
            );
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }

        $em = $args->getObjectManager();
        \assert($em instanceof EntityManagerInterface);
        $events = $this->pending;
        $this->pending = [];

        foreach ($events as $event) {
            $em->persist(new DomainEventLog(
                name: $event->getName(),
                aggregateType: $event->getAggregateType(),
                aggregateId: $event->getAggregateId(),
                workspace: $event->getWorkspace(),
                actor: $event->getActor(),
                payload: $event->getPayload(),
                occurredAt: $event->getOccurredAt(),
            ));
        }

        // Flush again — DomainEventLog is NOT in TRACKED, so this won't recurse.
        $em->flush();

        // Now hand the events off to anyone who wants to react — Automation
        // dispatcher, future webhook senders, etc. We dispatch AFTER the log
        // is persisted so consumers can rely on the log being in sync.
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }

    private function extractUuid(object $entity): ?Uuid
    {
        if (\method_exists($entity, 'getId')) {
            $id = $entity->getId();
            return $id instanceof Uuid ? $id : null;
        }
        return null;
    }

    private function extractWorkspace(object $entity): ?Workspace
    {
        if ($entity instanceof Workspace) {
            return $entity;
        }
        if (\method_exists($entity, 'getWorkspace')) {
            $ws = $entity->getWorkspace();
            return $ws instanceof Workspace ? $ws : null;
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotInserted(object $entity, \Doctrine\ORM\UnitOfWork $uow): array
    {
        $data = $uow->getOriginalEntityData($entity);
        $out = [];
        foreach ($data as $field => $value) {
            if ($field === 'password' || $field === 'roles') {
                continue;
            }
            $out[$field] = $this->normaliseValue($value);
        }
        return $out;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function normaliseChangeSet(array $changeSet): array
    {
        $out = [];
        foreach ($changeSet as $field => [$old, $new]) {
            if ($field === 'password' || $field === 'updatedAt') {
                continue;
            }
            $out[$field] = [
                'from' => $this->normaliseValue($old),
                'to' => $this->normaliseValue($new),
            ];
        }
        return $out;
    }

    /**
     * Adds awork-compatible contextual keys to the event payload so the
     * activity feed reads as "Sven assigned this to Alex" rather than only
     * "assignee changed from <uuid> to <uuid>".
     *
     * @param array<string, mixed> $payload
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @return array<string, mixed>
     */
    private function enrichPayload(object $entity, string $action, array $payload, array $changeSet): array
    {
        if ($entity instanceof Task && $action === GenericEntityChangedEvent::ACTION_CREATED) {
            $assignees = [];
            foreach ($entity->getAssignees() as $a) {
                $assignees[] = $this->userBrief($a);
            }
            if ($assignees !== []) {
                $payload['assignedUsers'] = $assignees;
            }
        }
        // Note: Multi-assignee changes are emitted by the join-table operations
        // themselves (Doctrine doesn't put ManyToMany changes into the changeset
        // of the owning entity). When a future "set-assignees" controller lands,
        // it should explicitly dispatch a "task.assignees_changed" event with
        // added/removed user-briefs.

        if (($entity instanceof ProjectMember || $entity instanceof WorkspaceMember) && $action !== GenericEntityChangedEvent::ACTION_UPDATED) {
            $payload['member'] = $this->userBrief($entity->getUser());
            if ($entity instanceof ProjectMember) {
                $project = $entity->getProject();
                $payload['project'] = ['id' => $project->getId()?->toRfc4122(), 'name' => $project->getName(), 'key' => $project->getKey()];
            }
        }

        return $payload;
    }

    /**
     * @return array{id: string|null, email: string, fullName: string}
     */
    private function userBrief(User $user): array
    {
        return [
            'id' => $user->getId()?->toRfc4122(),
            'email' => $user->getEmail(),
            'fullName' => $user->getFullName(),
        ];
    }

    private function normaliseValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (\is_object($value) && \method_exists($value, 'getId')) {
            $id = $value->getId();
            return $id instanceof Uuid ? $id->toRfc4122() : (string) $id;
        }
        if (\is_array($value)) {
            return array_map(fn ($v) => $this->normaliseValue($v), $value);
        }
        return $value;
    }
}
