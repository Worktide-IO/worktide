<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Enum\NotificationType;
use App\Entity\User;

/**
 * A resolver's answer: "notify THIS user about THIS, with this title/link".
 *
 * The event-level metadata (workspace, actor, sourceEventId, occurredAt) is
 * stamped on by {@see NotificationDispatcher} uniformly, so resolvers only
 * decide the recipient + human-facing payload.
 */
final class ResolvedNotification
{
    public function __construct(
        public readonly User $recipient,
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $link,
        public readonly ?string $body = null,
    ) {}
}
