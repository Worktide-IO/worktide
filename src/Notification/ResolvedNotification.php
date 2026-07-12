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
 *
 * The title is declared as a translation KEY (+ params), not a rendered string:
 * the dispatcher renders it in the RECIPIENT's language (it fans out one
 * notification per recipient, whose locale is known from stored state). The
 * body, when present, is free content (a comment excerpt, an incident line) and
 * is stored as-is.
 */
final class ResolvedNotification
{
    /**
     * @param array<string, string|int> $titleParams interpolation params for titleKey (e.g. ['%ticket%' => 'WORK-42'])
     */
    public function __construct(
        public readonly User $recipient,
        public readonly NotificationType $type,
        public readonly string $titleKey,
        public readonly string $link,
        public readonly ?string $body = null,
        public readonly array $titleParams = [],
    ) {}
}
