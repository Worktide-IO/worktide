<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Category of an inbox {@see \App\Entity\Notification}.
 *
 * The value is stable (persisted + sent to both SPAs, which map it to an
 * icon). Resolvers in {@see \App\Notification\Resolver} each emit one of
 * these; the list grows as new domain-event triggers are wired in.
 */
enum NotificationType: string
{
    case Mention = 'mention';
    case TaskAssigned = 'task_assigned';
    case Comment = 'comment';
    case System = 'system';
    case Ai = 'ai';
    case Launch = 'launch';
}
