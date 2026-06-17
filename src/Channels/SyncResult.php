<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Outcome of {@see SyncableAdapter::pushEntity()}. The framework
 * uses it to update the {@see \App\Entity\EntitySync} row with
 * fresh `externalUpdatedAt` / `etag` + remember errors.
 *
 * `conflict` is the "both sides changed" branch — the adapter
 * returns this when its conditional update (If-Match / version
 * pre-condition) is rejected by the external system. The
 * framework then applies the configured {@see \App\Entity\Enum\ConflictPolicy}
 * (or holds the change for manual review).
 */
final class SyncResult
{
    public function __construct(
        public readonly bool $synced,
        public readonly bool $conflict = false,
        public readonly bool $retry = false,
        public readonly ?\DateTimeImmutable $externalUpdatedAt = null,
        public readonly ?string $externalUrl = null,
        public readonly ?string $etag = null,
        public readonly ?string $reason = null,
    ) {}

    public static function synced(
        ?\DateTimeImmutable $externalUpdatedAt = null,
        ?string $externalUrl = null,
        ?string $etag = null,
    ): self {
        return new self(synced: true, externalUpdatedAt: $externalUpdatedAt, externalUrl: $externalUrl, etag: $etag);
    }

    public static function conflict(string $reason): self
    {
        return new self(synced: false, conflict: true, reason: $reason);
    }

    public static function retry(string $reason): self
    {
        return new self(synced: false, retry: true, reason: $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(synced: false, reason: $reason);
    }
}
