<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Channels\EntityTypeResolver;
use App\Channels\SyncReentryGuard;
use App\Entity\EntityChangeOutbox;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\EntitySyncRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * Records every Worktide-side change to an entity that's mirrored
 * in at least one external system into the
 * {@see EntityChangeOutbox} table. The worker
 * ({@see \App\Command\ProcessEntityChangeOutboxCommand}) picks the
 * rows up and dispatches to the adapters.
 *
 * Three hooks:
 *
 *   preUpdate    — capture changedFields + previousValues from
 *                  Doctrine's change-set so the outbox carries the
 *                  diff (not the whole entity), which keeps
 *                  external PATCH requests minimal.
 *   postPersist  — a brand-new local entity can be mirror-pushed
 *                  too (e.g. user creates a Task in Worktide that
 *                  should appear in Jira). The listener runs
 *                  AFTER the EntitySync row would be — by definition
 *                  there's no mapping yet, so the outbox row sits
 *                  pending until an adapter command explicitly
 *                  binds the new task to an external project.
 *   preRemove    — soft-deletes go through preUpdate (deletedAt
 *                  field change). Hard removes are captured here
 *                  so the adapter can mirror the deletion.
 *
 * Guarded by {@see SyncReentryGuard} so inbound writes don't
 * trigger an outbound push that races straight back to the
 * source.
 */
#[AsDoctrineListener(event: Events::preUpdate, priority: -50)]
#[AsDoctrineListener(event: Events::postPersist, priority: -50)]
#[AsDoctrineListener(event: Events::preRemove, priority: -50)]
final class EntitySyncRecordingListener
{
    public function __construct(
        private readonly EntitySyncRepository $entitySyncs,
        private readonly EntityTypeResolver $typeResolver,
        private readonly SyncReentryGuard $guard,
    ) {}

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        if ($this->guard->isActive()) {
            return;
        }
        $entity = $event->getObject();
        $slug = $this->typeResolver->tryFromInstance($entity);
        if ($slug === null) {
            return;
        }
        $id = $this->extractId($entity);
        if ($id === null) {
            return;
        }
        if (!$this->isMapped($slug, $id)) {
            return;
        }

        // Sparse diff — only what the changeset reports as changed.
        $changedFields = [];
        $previousValues = [];
        foreach ($event->getEntityChangeSet() as $field => [$old, $new]) {
            // Skip noisy auto-managed fields the adapter doesn't care
            // about; they'd cause every save to enqueue useless work.
            if (\in_array($field, ['updatedAt', 'version', 'updatedByUser'], true)) {
                continue;
            }
            $changedFields[$field] = $this->normalise($new);
            $previousValues[$field] = $this->normalise($old);
        }
        if ($changedFields === []) {
            return;
        }

        $this->enqueue($entity, $slug, $id, $changedFields, $previousValues, false, $event->getObjectManager());
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        if ($this->guard->isActive()) {
            return;
        }
        $entity = $event->getObject();
        $slug = $this->typeResolver->tryFromInstance($entity);
        if ($slug === null) {
            return;
        }
        // Brand-new entity has no EntitySync row yet by definition;
        // we still enqueue so an adapter binding the new entity to
        // an external counterpart can fire on the next worker run.
        // The worker skips when findByEntity() returns empty —
        // explicit no-op rather than a missing row.
        $id = $this->extractId($entity);
        if ($id === null) {
            return;
        }
        if (!$this->isMapped($slug, $id)) {
            return;
        }
        $this->enqueue($entity, $slug, $id, ['__created' => true], [], false, $event->getObjectManager());
    }

    public function preRemove(PreRemoveEventArgs $event): void
    {
        if ($this->guard->isActive()) {
            return;
        }
        $entity = $event->getObject();
        $slug = $this->typeResolver->tryFromInstance($entity);
        if ($slug === null) {
            return;
        }
        $id = $this->extractId($entity);
        if ($id === null) {
            return;
        }
        if (!$this->isMapped($slug, $id)) {
            return;
        }
        $this->enqueue($entity, $slug, $id, [], [], true, $event->getObjectManager());
    }

    private function isMapped(string $slug, Uuid $id): bool
    {
        return $this->entitySyncs->findByEntity($slug, $id) !== [];
    }

    /**
     * @param array<string, mixed> $changedFields
     * @param array<string, mixed> $previousValues
     */
    private function enqueue(
        object $entity,
        string $slug,
        Uuid $id,
        array $changedFields,
        array $previousValues,
        bool $isDelete,
        object $em,
    ): void {
        $workspace = $this->extractWorkspace($entity);
        if ($workspace === null) {
            return;
        }
        $outbox = (new EntityChangeOutbox())
            ->setWorkspace($workspace)
            ->setEntityType($slug)
            ->setEntityId($id)
            ->setChangedFields($changedFields)
            ->setPreviousValues($previousValues)
            ->setIsDelete($isDelete);
        $em->persist($outbox);
        $meta = $em->getClassMetadata(EntityChangeOutbox::class);
        $em->getUnitOfWork()->computeChangeSet($meta, $outbox);
    }

    private function extractId(object $entity): ?Uuid
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }
        $id = $entity->getId();
        return $id instanceof Uuid ? $id : null;
    }

    private function extractWorkspace(object $entity): ?\App\Entity\Workspace
    {
        // Every syncable entity uses WorkspaceScopedTrait by
        // convention. The trait exposes getWorkspace() — duck-type
        // here so we don't need a marker interface.
        $traits = class_uses($entity) ?: [];
        if (!isset($traits[WorkspaceScopedTrait::class]) && !method_exists($entity, 'getWorkspace')) {
            return null;
        }
        return $entity->getWorkspace();
    }

    /**
     * Convert change-set values into something JSON can persist:
     * DateTimes to ISO strings, entities to their IRI, enums to
     * their `value`, scalars + arrays pass through.
     */
    private function normalise(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format(\DateTimeInterface::ATOM);
        }
        if ($v instanceof Uuid) {
            return $v->toRfc4122();
        }
        if ($v instanceof \BackedEnum) {
            return $v->value;
        }
        if (is_object($v) && method_exists($v, 'getId')) {
            $id = $v->getId();
            if ($id instanceof Uuid) {
                $class = $v::class;
                $base = strtolower((new \ReflectionClass($class))->getShortName());
                // Pluralise crudely for the IRI — most entities are
                // foo → foos. The adapter doesn't actually navigate
                // these strings; they're audit-only.
                return sprintf('/v1/%s/%s', $base . 's', $id->toRfc4122());
            }
        }
        if (is_array($v)) {
            return array_map(fn ($x) => $this->normalise($x), $v);
        }
        return $v;
    }
}
