<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\DomainEventLog;
use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Fans one {@see DomainEventLog} out to per-recipient {@see Notification}s.
 *
 * Runs every tagged {@see NotificationResolverInterface}. Persistence is
 * deliberately split from the flush: {@see build()} only constructs (and
 * `persist()`s) entities — the caller (the postFlush subscriber) owns the
 * single flush, then calls {@see publish()} so Mercure updates carry real
 * ids. Dedupe is enforced two ways: an in-memory key guards against two
 * resolvers producing the same (recipient, type) for one event, and a DB
 * pre-check guards against re-processing the same event (the unique index
 * `notification_dedupe` is the last line of defence).
 */
final class NotificationDispatcher
{
    /**
     * @param iterable<NotificationResolverInterface> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers,
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifications,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Build + persist (no flush) the notifications for one event.
     *
     * @return list<Notification> the freshly-created rows, for the caller to
     *                            flush and then hand to {@see publish()}
     */
    public function build(DomainEventLog $event): array
    {
        $eventId = $event->getId();
        $created = [];
        $seen = [];

        foreach ($this->resolvers as $resolver) {
            if (!$resolver->supports($event)) {
                continue;
            }
            foreach ($resolver->resolve($event) as $resolved) {
                $recipientId = $resolved->recipient->getId()?->toRfc4122();
                if ($recipientId === null) {
                    continue;
                }
                $key = $recipientId . '|' . $resolved->type->value;
                if (isset($seen[$key])) {
                    continue; // two resolvers, same (recipient, type) — keep one
                }
                $seen[$key] = true;

                // Skip if this exact event already produced this notification
                // for the recipient (idempotent re-processing).
                if ($eventId !== null
                    && $this->notifications->existsFor($resolved->recipient, $eventId, $resolved->type)) {
                    continue;
                }

                $notification = new Notification(
                    recipient: $resolved->recipient,
                    type: $resolved->type,
                    title: mb_substr($resolved->title, 0, 255),
                    link: mb_substr($resolved->link, 0, 512),
                    body: $resolved->body,
                    workspace: $event->getWorkspace(),
                    actor: $event->getActor(),
                    sourceEventId: $eventId,
                    occurredAt: $event->getOccurredAt(),
                );
                $this->em->persist($notification);
                $created[] = $notification;
            }
        }

        return $created;
    }

    /**
     * Push each new notification to its recipient's private Mercure topic
     * `/v1/users/<id>/notifications`, matching the per-user topic convention
     * used by {@see \App\Controller\Api\UserPreferencesController}.
     *
     * @param list<Notification> $notifications
     */
    public function publish(array $notifications): void
    {
        foreach ($notifications as $n) {
            $recipientId = $n->getRecipient()->getId()?->toRfc4122();
            if ($recipientId === null) {
                continue;
            }
            // Best-effort: the DB row is the source of truth. A hub that is
            // down / unreachable must never abort the flush that created the
            // notification, so swallow-and-log any publish failure.
            try {
                $this->hub->publish(new Update(
                    topics: ['/v1/users/' . $recipientId . '/notifications'],
                    data: json_encode([
                        'id' => $n->getId()?->toRfc4122(),
                        'type' => $n->getType()->value,
                        'title' => $n->getTitle(),
                        'body' => $n->getBody(),
                        'link' => $n->getLink(),
                        'occurredAt' => $n->getOccurredAt()->format(\DateTimeInterface::ATOM),
                        'read' => false,
                    ]) ?: '{}',
                    private: true,
                ));
            } catch (\Throwable $e) {
                $this->logger->warning('Notification Mercure publish failed: {message}', [
                    'message' => $e->getMessage(),
                    'recipient' => $recipientId,
                ]);
            }
        }
    }
}
