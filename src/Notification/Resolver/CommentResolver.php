<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\Comment;
use App\Entity\DomainEventLog;
use App\Entity\Enum\NotificationType;
use App\Entity\Enum\WatchableTarget;
use App\Notification\NotificationResolverInterface;
use App\Notification\RecipientResolver;
use App\Notification\ResolvedNotification;
use App\Repository\CommentRepository;
use App\Repository\TaskRepository;
use App\Repository\WatchRepository;

/**
 * New comment → notify the people who care:
 *
 *  - STAFF: everyone watching the comment's target (a Task/Project/Document,
 *    via {@see WatchRepository}) plus anyone @mentioned in the comment body,
 *    minus the author. Staff SPA links.
 *  - PORTAL: when the comment is a staff reply on a customer ticket (a Task on
 *    an external customer project, not marked hidden-for-connect-users), the
 *    customer's portal users. Portal SPA link (`/tickets/<id>`).
 *
 * The two audiences are disjoint (portal users can't hold a Watch), so no
 * recipient is notified twice.
 */
final class CommentResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly WatchRepository $watches,
        private readonly TaskRepository $tasks,
        private readonly RecipientResolver $recipients,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return $event->getName() === 'comment.created';
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $id = $event->getAggregateId();
        $comment = $id !== null ? $this->comments->find($id) : null;
        if (!$comment instanceof Comment) {
            return;
        }

        $authorId = $comment->getAuthor()->getId()?->toRfc4122();
        $target = $comment->getTarget();
        $targetId = $comment->getTargetId();
        $body = $this->excerpt($comment->getContent());

        // --- Staff: watchers + in-comment mentions ---------------------------
        $staff = [];
        $watchable = WatchableTarget::tryFrom($target->value);
        if ($watchable !== null) {
            foreach ($this->watches->findWatchersFor($watchable, $targetId) as $user) {
                $uid = $user->getId()?->toRfc4122();
                if ($uid !== null && $uid !== $authorId) {
                    $staff[$uid] = $user;
                }
            }
        }
        foreach ($comment->getMentions() as $uuid) {
            $user = $this->recipients->userFromUuidString($uuid);
            $uid = $user?->getId()?->toRfc4122();
            if ($user !== null && $uid !== null && $uid !== $authorId) {
                $staff[$uid] = $user;
            }
        }
        $staffLink = $this->staffLink($target, $targetId->toRfc4122());
        foreach ($staff as $user) {
            yield new ResolvedNotification(
                recipient: $user,
                type: NotificationType::Comment,
                titleKey: 'notification.comment_new',
                link: $staffLink,
                body: $body,
            );
        }

        // --- Portal: staff reply on a customer ticket ------------------------
        if ($target->value !== WatchableTarget::Task->value || $comment->isHiddenForConnectUsers()) {
            return;
        }
        $task = $this->tasks->find($targetId);
        $customer = $task?->getProject()?->getCustomer();
        if ($task === null || $customer === null || !($task->getProject()?->isExternal() ?? false)) {
            return;
        }
        foreach ($this->recipients->portalUsersOfCustomer($customer) as $user) {
            if ($user->getId()?->toRfc4122() === $authorId) {
                continue;
            }
            yield new ResolvedNotification(
                recipient: $user,
                type: NotificationType::Comment,
                titleKey: 'notification.comment_reply',
                titleParams: ['%ticket%' => $task->getIdentifier()],
                link: '/tickets/' . $targetId->toRfc4122(),
                body: $body,
            );
        }
    }

    private function staffLink(\App\Entity\Enum\CommentTarget $target, string $targetId): string
    {
        return match ($target->value) {
            'project' => '/projects/' . $targetId,
            'document' => '/documents',
            default => '/tasks',
        };
    }

    private function excerpt(string $text, int $max = 140): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
