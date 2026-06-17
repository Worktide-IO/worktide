<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Adapter-produced description of one external entity. The framework
 * uses this to upsert the matching Worktide-side entity (creating it
 * if no EntitySync row maps the externalId yet, or updating it if
 * the conflict policy allows).
 *
 * Fields are intentionally minimal — the adapter writes only what
 * makes sense for the entityType. A Jira issue snapshot fills
 * `title`, `body`, `status`, `assigneeEmail`, etc.; a CalDAV
 * event snapshot fills `title`, `start`, `end`, `attendees`.
 *
 * `fields` is the adapter-specific normalised representation. Keys
 * are Worktide-side field names so the framework can apply them to
 * the entity without per-adapter dispatch.
 */
final class EntitySnapshot
{
    /**
     * @param array<string, mixed> $fields  Worktide-shape field map
     * @param array<string, mixed> $sourceMetadata  raw bits the adapter wants to remember (Jira renderer, project_id, …)
     */
    public function __construct(
        public readonly string $entityType,
        public readonly string $externalId,
        public readonly array $fields = [],
        public readonly ?\DateTimeImmutable $externalUpdatedAt = null,
        public readonly ?string $externalUrl = null,
        public readonly ?string $etag = null,
        public readonly array $sourceMetadata = [],
        /**
         * `true` when the external side reports the entity as
         * deleted/closed/archived (Jira `resolution = Done` may
         * count, CalDAV 404 definitely does). The framework
         * decides whether to soft-delete the Worktide-side entity
         * or just mark the mapping as stale.
         */
        public readonly bool $remoteDeleted = false,
    ) {}
}
