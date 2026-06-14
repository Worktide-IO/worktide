<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Webhook;
use App\Event\GenericEntityChangedEvent;
use App\Message\SendWebhookMessage;
use App\Repository\WebhookRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Listens for every dispatched GenericEntityChangedEvent and queues a
 * SendWebhookMessage per matching, active Webhook.
 *
 * Webhooks scoped to a workspace are only sent for events from that workspace;
 * workspace-less events (rare — e.g. cross-tenant ops) are skipped.
 *
 * Webhook on Webhook/WebhookDelivery aggregate types is intentionally NOT
 * filtered out here — a consumer that subscribes to "webhook.*" can observe
 * its own lifecycle if it wants. Recursion is prevented elsewhere:
 * WebhookDelivery is not in DomainEventEmitter::TRACKED, so delivery rows
 * never fire events themselves.
 */
final class WebhookDispatcher implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookRepository $webhooks,
        private readonly MessageBusInterface $bus,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            GenericEntityChangedEvent::class => ['onDomainEvent', -50],
        ];
    }

    public function onDomainEvent(GenericEntityChangedEvent $event): void
    {
        $workspace = $event->getWorkspace();
        $subscribers = $this->webhooks->findActiveForWorkspace($workspace);
        if ($subscribers === []) {
            return;
        }
        $name = $event->getName();
        $payload = $this->buildPayload($event);

        foreach ($subscribers as $hook) {
            if (!$hook->matches($name)) {
                continue;
            }
            $id = $hook->getId();
            if ($id === null) {
                // Should not happen — active webhooks come from the DB.
                continue;
            }
            $this->bus->dispatch(new SendWebhookMessage(
                webhookId: $id,
                eventPayload: $payload,
            ));
        }
    }

    /**
     * @return array{
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
    private function buildPayload(GenericEntityChangedEvent $event): array
    {
        return [
            // event-level UUID derived from aggregate+occurredAt; the wire id
            // serves consumer deduplication, not lookup.
            'id' => $this->deriveEventId($event),
            'name' => $event->getName(),
            'aggregateType' => $event->getAggregateType(),
            'aggregateId' => $event->getAggregateId()?->toRfc4122(),
            'workspaceId' => $event->getWorkspace()?->getId()?->toRfc4122(),
            'actorId' => $event->getActor()?->getId()?->toRfc4122(),
            'occurredAt' => $event->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'payload' => $event->getPayload(),
        ];
    }

    private function deriveEventId(GenericEntityChangedEvent $event): string
    {
        $key = sprintf(
            '%s|%s|%s|%s',
            $event->getName(),
            $event->getAggregateId()?->toRfc4122() ?? '-',
            $event->getOccurredAt()->format('U.u'),
            bin2hex(random_bytes(4)),
        );
        return md5($key);
    }
}
