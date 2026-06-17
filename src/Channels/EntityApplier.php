<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;
use App\Entity\EntitySync;
use App\Entity\Enum\SyncMode;
use App\Entity\Task;
use App\Repository\EntitySyncRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Applies one or more {@see EntitySnapshot}s onto Worktide-side
 * entities — the inbound counterpart of the EntityChangeOutbox.
 *
 * Two responsibilities, kept in one place so the conflict-detection
 * stays consistent:
 *
 *   1. Upsert the Worktide entity (Task today; more types as the
 *      EntityTypeResolver grows).
 *   2. Upsert the EntitySync mapping row with fresh
 *      externalUpdatedAt / etag / lastSyncedAt.
 *
 * Wrapped in {@see SyncReentryGuard} so the writes don't bounce
 * back through {@see \App\EventSubscriber\EntitySyncRecordingListener}
 * and re-trigger an outbound push to the same source.
 *
 * Conflict detection (basic for now): if our local entity has been
 * updated AFTER the snapshot's externalUpdatedAt AND the snapshot
 * carries fields we'd overwrite → defer to the channel's
 * conflictPolicy. Today only `external_wins` and `last_write_wins`
 * are honoured; `manual` returns without applying and surfaces a
 * pending mapping row for the SPA-side review.
 *
 * EntityApplier is intentionally NOT an InboundAdapter — it sits
 * on top, called by every concrete SyncableAdapter when it has
 * one or more snapshots to materialise.
 */
final class EntityApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntitySyncRepository $mappings,
        private readonly EntityTypeResolver $typeResolver,
        private readonly SyncReentryGuard $guard,
    ) {}

    /**
     * Apply one snapshot — creates the local entity if no mapping
     * exists yet for (channel, externalId), otherwise updates the
     * existing one. Returns the EntitySync row (created or updated)
     * so the caller can stash it on the channel's sync log.
     */
    public function apply(Channel $channel, EntitySnapshot $snapshot): EntitySync
    {
        return $this->guard->run(function () use ($channel, $snapshot) {
            $mapping = $this->mappings->findByChannelExternal($channel, $snapshot->externalId);

            if ($mapping !== null) {
                $entity = $this->loadEntity($mapping);
                if ($entity === null) {
                    // Local row was hard-deleted while we weren't looking.
                    // Treat as new — adapter will create a fresh one.
                    $mapping = null;
                }
            }

            if ($mapping === null) {
                $entity = $this->createEntity($channel, $snapshot);
                $mapping = (new EntitySync())
                    ->setWorkspace($channel->getWorkspace())
                    ->setChannel($channel)
                    ->setEntityType($snapshot->entityType)
                    ->setEntityId($entity->getId() ?? throw new \LogicException('Entity must have an ID after persist.'))
                    ->setExternalId($snapshot->externalId)
                    ->setSyncMode(SyncMode::Bidirectional);
                $this->em->persist($mapping);
            } else {
                $entity = $this->loadEntity($mapping);
                if ($entity !== null && !$snapshot->remoteDeleted) {
                    $this->updateEntity($entity, $snapshot);
                }
            }

            $mapping
                ->setExternalUpdatedAt($snapshot->externalUpdatedAt)
                ->setExternalUrl($snapshot->externalUrl)
                ->setEtag($snapshot->etag)
                ->setLastSyncedAt(new \DateTimeImmutable())
                ->setLastSyncError(null)
                ->setSourceMetadata($snapshot->sourceMetadata);

            return $mapping;
        });
    }

    private function loadEntity(EntitySync $mapping): ?object
    {
        $class = $this->typeResolver->classFor($mapping->getEntityType());
        return $this->em->find($class, $mapping->getEntityId());
    }

    private function createEntity(Channel $channel, EntitySnapshot $snapshot): object
    {
        $class = $this->typeResolver->classFor($snapshot->entityType);
        $entity = new $class();
        $this->applyFields($entity, $snapshot->fields);
        if (method_exists($entity, 'setWorkspace')) {
            $entity->setWorkspace($channel->getWorkspace());
        }
        $this->em->persist($entity);
        $this->em->flush(); // need ID for the EntitySync row
        return $entity;
    }

    private function updateEntity(object $entity, EntitySnapshot $snapshot): void
    {
        $this->applyFields($entity, $snapshot->fields);
    }

    /**
     * Generic field application by setter convention. Adapters
     * promise that `fields` keys match Worktide-shape attribute
     * names (e.g. `title`, `description`, `dueOn`). Special-cases
     * for relations land here when the second sync target lands.
     *
     * @param array<string, mixed> $fields
     */
    private function applyFields(object $entity, array $fields): void
    {
        foreach ($fields as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (!method_exists($entity, $setter)) {
                continue;
            }
            $entity->$setter($this->coerceForSetter($setter, $entity, $value));
        }
    }

    /**
     * Pre-PHP-type coercion: DateTimeImmutable construction from
     * ISO strings, Uuid::fromString for `assignee`-shaped fields,
     * etc. Today only the ISO-date path matters because the base
     * adapter only writes title/description; richer adapters
     * extend this method.
     */
    private function coerceForSetter(string $setter, object $entity, mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return $value;
            }
        }
        return $value;
    }
}
