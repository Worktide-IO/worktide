<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to POST a webhook payload to one subscriber. The handler
 * resolves the Webhook entity by id, hashes the body with the stored secret,
 * fires the request, and persists a WebhookDelivery row.
 *
 * The full event payload is captured here (not re-fetched) so retries replay
 * exactly the body that was originally signed.
 *
 * @phpstan-type EventPayload array{
 *   id: string,
 *   name: string,
 *   aggregateType: string,
 *   aggregateId: string|null,
 *   workspaceId: string|null,
 *   actorId: string|null,
 *   occurredAt: string,
 *   payload: array<string, mixed>
 * }
 */
final class SendWebhookMessage
{
    /**
     * @param EventPayload $eventPayload
     */
    public function __construct(
        private readonly Uuid $webhookId,
        private readonly array $eventPayload,
        private readonly int $attempt = 1,
    ) {}

    public function getWebhookId(): Uuid
    {
        return $this->webhookId;
    }

    /** @return EventPayload */
    public function getEventPayload(): array
    {
        return $this->eventPayload;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }
}
