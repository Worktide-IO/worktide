<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to run one channel's inbound sync now — dispatched by the
 * operator-facing `POST /v1/channels/{id}/sync` endpoint so a long-running pull
 * (Lexoffice/Awork API fetch, Redmine entity pull) never blocks the request.
 *
 * Carries only the channel id and re-loads on handle, so a redelivery acts on
 * current channel state. Routed to the slow `ai_agents` transport so a minutes-
 * long import can't starve the fast async queue (webhooks/mail/mercure).
 */
final class RunChannelSyncMessage
{
    public function __construct(
        private readonly Uuid $channelId,
        private readonly bool $live = true,
    ) {}

    public function getChannelId(): Uuid
    {
        return $this->channelId;
    }

    public function isLive(): bool
    {
        return $this->live;
    }
}
