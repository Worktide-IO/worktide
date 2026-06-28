<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Outcome of a {@see SocialPublisherAdapter::publish()} attempt. Mirrors
 * {@see OutboundResult} but adds `permalink` — social networks return a public
 * URL to the live post, which the SPA links to.
 *
 * `published` + `externalId` on success.
 * `failed` + `reason` on permanent failure (bad credentials, rejected content).
 * `retry` for transient failures (network blip, 5xx) — the publisher re-queues
 *          based on the target's attemptCount.
 */
final class SocialPublishResult
{
    public function __construct(
        public readonly bool $published,
        public readonly bool $failed = false,
        public readonly bool $retry = false,
        public readonly ?string $externalId = null,
        public readonly ?string $permalink = null,
        public readonly ?string $reason = null,
    ) {}

    public static function published(string $externalId, ?string $permalink = null): self
    {
        return new self(published: true, externalId: $externalId, permalink: $permalink);
    }

    public static function failed(string $reason): self
    {
        return new self(published: false, failed: true, reason: $reason);
    }

    public static function retry(string $reason): self
    {
        return new self(published: false, retry: true, reason: $reason);
    }
}
