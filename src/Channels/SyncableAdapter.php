<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;
use App\Entity\EntitySync;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adapter capability for **entity sync** (vs the existing
 * {@see InboundAdapter}, which models event streams).
 *
 * The distinction matters: an Inbound mail is an event — append-only,
 * the source never says "scratch that, version 2 of this mail". A
 * Jira issue or a calendar event is an entity — both sides own a
 * mutable version, mappings exist for the entity's whole life, and
 * a conflict is possible if both sides changed independently.
 *
 * Adapters that implement this interface MAY also implement
 * {@see InboundAdapter} and {@see OutboundAdapter} (Jira does — the
 * webhook arrival is the "inbound", the entity update is the
 * "syncable"). Most calendar adapters implement only this one.
 *
 * The adapter is responsible for the field-mapping; the framework
 * coordinates the queue + state.
 */
interface SyncableAdapter
{
    public function getCode(): string;

    /**
     * Which Worktide entity-type slugs this adapter can sync.
     * E.g. `['task', 'comment']` for Jira/Redmine,
     * `['calendar_event', 'time_entry']` for CalDAV.
     *
     * @return list<string>
     */
    public function supportedEntityTypes(): array;

    /**
     * Pull-style discovery — fetch the full or incremental set of
     * external records and return their normalised snapshots. The
     * framework persists / updates the Worktide-side entities and
     * the matching {@see EntitySync} rows.
     *
     * @return list<EntitySnapshot>
     */
    public function pullEntities(Channel $channel, ?\DateTimeImmutable $changedSince = null): array;

    /**
     * Push-style write — Worktide-side change is being mirrored to
     * the external system. The adapter handles the field mapping
     * and the conditional update (If-Match / If-Unmodified-Since).
     * Returns updated state so the framework can persist the new
     * `externalUpdatedAt` / `etag` on the EntitySync row.
     */
    public function pushEntity(EntitySync $mapping, EntityChange $change): SyncResult;

    /**
     * Webhook ingest for entity-level events ("issue updated",
     * "event moved"). Returns the list of changes the framework
     * should propagate to local entities.
     *
     * Throw {@see WebhookNotSupportedException} when the adapter
     * is pull-only.
     *
     * @return list<EntitySnapshot>
     */
    public function receiveEntityWebhook(Channel $channel, Request $request): array;
}
