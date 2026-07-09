<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\DomainEventLog;

/**
 * Turns one {@see DomainEventLog} into zero or more {@see ResolvedNotification}s.
 *
 * One implementation per trigger family (mention, task assignment, comment,
 * system incident, launch, AI). Implementations MUST be side-effect free and
 * defensive — return an empty result rather than throwing when the event's
 * payload/aggregate can't be resolved, so a single bad event never aborts the
 * flush that produced it.
 *
 * All implementations are auto-tagged `worktide.notification_resolver` (see
 * config/services.yaml `_instanceof`) and injected into the dispatcher.
 */
interface NotificationResolverInterface
{
    public function supports(DomainEventLog $event): bool;

    /**
     * @return iterable<ResolvedNotification>
     */
    public function resolve(DomainEventLog $event): iterable;
}
