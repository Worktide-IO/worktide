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

    // File-sharing + ticket-update triggers, delivered debounced (batched):
    case CustomerFileUpload = 'customer_file_upload'; // customer uploaded → notify responsible staff
    case FileShared = 'file_shared';                  // staff shared a file → notify the customer's portal contacts
    case TaskUpdated = 'task_updated';                // an assigned ticket changed → notify its assignees

    /**
     * Types whose async (email/chat) delivery is collected and sent as ONE
     * batched message after the recipient's debounce window, instead of
     * instantly per event. In-app/Mercure is unaffected (always immediate).
     * See {@see \App\Command\NotificationsFlushBatchCommand}.
     */
    public function isBatchable(): bool
    {
        return match ($this) {
            self::CustomerFileUpload, self::FileShared, self::TaskAssigned, self::TaskUpdated => true,
            default => false,
        };
    }
}
