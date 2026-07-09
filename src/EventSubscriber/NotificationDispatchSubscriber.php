<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\DomainEventLog;
use App\Notification\NotificationDispatcher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Fans persisted {@see DomainEventLog} rows out into per-user notifications.
 *
 * Timing: {@see DomainEventEmitterSubscriber} persists the event rows in its
 * OWN postFlush (which triggers a follow-up flush). We hook that: `postPersist`
 * collects every DomainEventLog written, then the corresponding `postFlush`
 * runs the collected events through the dispatcher, flushes the new
 * Notification rows once, and publishes them to Mercure.
 *
 * Re-entrancy: our own flush re-enters postFlush. A `$draining` guard makes
 * that pass a no-op, and Notification is deliberately NOT tracked by the
 * emitter, so writing notifications produces no further domain events — the
 * cascade terminates.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postFlush)]
final class NotificationDispatchSubscriber
{
    /** @var list<DomainEventLog> */
    private array $pending = [];

    private bool $draining = false;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof DomainEventLog) {
            $this->pending[] = $entity;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->draining || $this->pending === []) {
            return;
        }

        $events = $this->pending;
        $this->pending = [];
        $this->draining = true;
        try {
            $em = $args->getObjectManager();
            $created = [];
            foreach ($events as $event) {
                foreach ($this->dispatcher->build($event) as $notification) {
                    $created[] = $notification;
                }
            }
            if ($created !== []) {
                $em->flush();
                $this->dispatcher->publish($created);
            }
        } finally {
            $this->draining = false;
        }
    }
}
