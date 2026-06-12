<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\DomainEventLog;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectStatus;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\TaskStatus;
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
    ];

    /** @var list<GenericEntityChangedEvent> */
    private array $pending = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $type = self::TRACKED[$entity::class] ?? null;
            if ($type === null) {
                continue;
            }
            $this->pending[] = new GenericEntityChangedEvent(
                aggregateType: $type,
                aggregateId: $this->extractUuid($entity),
                action: GenericEntityChangedEvent::ACTION_CREATED,
                payload: $this->snapshotInserted($entity, $uow),
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
            $this->pending[] = new GenericEntityChangedEvent(
                aggregateType: $type,
                aggregateId: $this->extractUuid($entity),
                action: GenericEntityChangedEvent::ACTION_UPDATED,
                payload: $this->normaliseChangeSet($changeSet),
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
