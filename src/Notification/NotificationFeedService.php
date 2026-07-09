<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Read-side of the inbox, shared by the staff (`/v1/me/notifications`) and
 * portal (`/v1/portal/notifications`) controllers so both return an identical
 * shape: a keyset page of items + the live unread count + a next cursor.
 */
final class NotificationFeedService
{
    public const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly NotificationRepository $repo,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, unreadCount: int, nextCursor: ?string}
     */
    public function feed(User $recipient, ?string $cursor, int $limit, bool $unreadOnly): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $cursorUuid = ($cursor !== null && $cursor !== '' && Uuid::isValid($cursor))
            ? Uuid::fromString($cursor)
            : null;

        $rows = $this->repo->paginateForRecipient($recipient, $cursorUuid, $limit, $unreadOnly);

        $items = array_map([$this, 'item'], $rows);
        $nextCursor = \count($rows) === $limit
            ? end($rows)->getId()?->toRfc4122()
            : null;

        return [
            'items' => $items,
            'unreadCount' => $this->repo->countUnread($recipient),
            'nextCursor' => $nextCursor,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(Notification $n): array
    {
        return [
            'id' => $n->getId()?->toRfc4122(),
            'type' => $n->getType()->value,
            'title' => $n->getTitle(),
            'body' => $n->getBody(),
            'link' => $n->getLink(),
            'occurredAt' => $n->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'read' => $n->isRead(),
            'readAt' => $n->getReadAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
