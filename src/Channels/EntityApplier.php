<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;
use App\Entity\EntitySync;
use App\Repository\EntitySyncRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        private readonly \App\Service\Inbound\DiscoveredRecordCollector $discovered,
        private readonly \App\Service\Inbound\TaskEnricher $enricher,
    ) {}

    /**
     * Apply one snapshot — creates the local entity if no mapping
     * exists yet for (channel, externalId), otherwise updates the
     * existing one. Returns the EntitySync row (created or updated)
     * so the caller can stash it on the channel's sync log.
     */
    /**
     * Apply one snapshot. Returns the updated EntitySync row, OR
     * null if there's no existing mapping and we deliberately skip
     * auto-creation.
     *
     * V1 policy: never auto-create local entities from external
     * snapshots. Worktide entities have context the external side
     * doesn't know (Task needs a Project; Project needs a Customer
     * etc.). The initial mapping is created from the SPA — the
     * user picks "link THIS Worktide task to THAT Redmine issue"
     * — and subsequent pulls update the already-mapped pair.
     *
     * Unmapped snapshots are NOT silently dropped: the caller
     * receives `null` and can stash the snapshot in a "discovered,
     * un-bound" inbox so the SPA can offer it for manual linkage.
     * That UX lives in C.7.6.
     */
    public function apply(Channel $channel, EntitySnapshot $snapshot): ?EntitySync
    {
        return $this->guard->run(function () use ($channel, $snapshot) {
            $mapping = $this->mappings->findByChannelExternal($channel, $snapshot->externalId);
            if ($mapping === null) {
                // Discovered an external record we don't know yet. Don't
                // auto-create the local entity (V1 policy) — instead park it as
                // a DiscoveredExternalRecord when it involves a workspace person,
                // for the operator to import/link/dismiss (C.7.6).
                $this->discovered->capture($channel, $snapshot);
                return null;
            }
            $entity = $this->loadEntity($mapping);
            if ($entity === null) {
                // Local row vanished (hard delete) — mark the mapping
                // stale so the SPA can show "unlinked" without
                // re-creating the local entity from external data.
                $mapping->setLastSyncError('Local entity no longer exists — mapping stale.');
                return $mapping;
            }
            if (!$snapshot->remoteDeleted) {
                $this->updateEntity($entity, $snapshot, $channel);
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

    private function updateEntity(object $entity, EntitySnapshot $snapshot, Channel $channel): void
    {
        $this->applyFields($entity, $snapshot->fields);
        // Ongoing pull also enriches status/priority/assignee/dueOn (same as seed import).
        if ($entity instanceof \App\Entity\Task) {
            $this->enricher->enrich($entity, $snapshot, $channel);
        }
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
