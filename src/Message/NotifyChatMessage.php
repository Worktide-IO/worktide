<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to push one notification to one user's chat webhook. The
 * notifier resolves the recipient's webhook + renders the deep-link, then hands
 * this to the async handler so the outbound HTTP POST never blocks the flush.
 */
final class NotifyChatMessage
{
    public function __construct(
        private readonly Uuid $webhookId,
        private readonly string $title,
        private readonly ?string $body,
        private readonly string $actionUrl,
    ) {}

    public function getWebhookId(): Uuid
    {
        return $this->webhookId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getActionUrl(): string
    {
        return $this->actionUrl;
    }
}
