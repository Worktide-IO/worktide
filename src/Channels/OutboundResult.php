<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Outcome of an {@see OutboundAdapter::send()} attempt.
 *
 * `sent` + `externalId` populated on success.
 * `failed` + `reason` populated on permanent failure (auth, malformed
 *           recipient, hard bounce surfaced synchronously).
 * `retry`  populated for transient failures (network blip, 5xx) — the
 *           OutboundQueue worker decides whether to re-queue based
 *           on attemptCount.
 */
final class OutboundResult
{
    public function __construct(
        public readonly bool $sent,
        public readonly bool $failed = false,
        public readonly bool $retry = false,
        public readonly ?string $externalId = null,
        public readonly ?string $reason = null,
    ) {}

    public static function sent(string $externalId): self
    {
        return new self(sent: true, externalId: $externalId);
    }

    public static function failed(string $reason): self
    {
        return new self(sent: false, failed: true, reason: $reason);
    }

    public static function retry(string $reason): self
    {
        return new self(sent: false, retry: true, reason: $reason);
    }
}
