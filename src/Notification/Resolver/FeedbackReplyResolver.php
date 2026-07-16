<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\Comment;
use App\Entity\DomainEventLog;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\NotificationType;
use App\Notification\NotificationResolverInterface;
use App\Notification\ResolvedNotification;
use App\Repository\CommentRepository;
use App\Repository\FeedbackSubmissionRepository;
use App\Repository\TaskRepository;
use App\Service\Feedback\FeedbackProjectLocator;

/**
 * New reply on a shared feedback-board ticket → notify the original reporter
 * (unless they wrote it themselves). Anonymized title; links to /feedback/<id>
 * (resolved by whichever SPA the recipient uses).
 *
 * Only fires for comments on tasks in the feedback project, so it never
 * collides with {@see CommentResolver} (whose portal branch requires an
 * external, customer-owned project — the feedback project is neither).
 */
final class FeedbackReplyResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly TaskRepository $tasks,
        private readonly FeedbackSubmissionRepository $submissions,
        private readonly FeedbackProjectLocator $locator,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return $event->getName() === 'comment.created';
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $id = $event->getAggregateId();
        $comment = $id !== null ? $this->comments->find($id) : null;
        if (!$comment instanceof Comment || $comment->getTarget()->value !== CommentTarget::Task->value) {
            return;
        }

        $task = $this->tasks->find($comment->getTargetId());
        if ($task === null || !$this->locator->isFeedbackProject($task->getProject())) {
            return;
        }

        $submission = $this->submissions->findOneByTask($task);
        $reporter = $submission?->getSubmitterUser() ?? $submission?->getSubmitterContact()?->getLinkedUser();
        if ($reporter === null) {
            return;
        }

        // Don't notify the reporter about their own reply.
        if ($reporter->getId()?->toRfc4122() === $comment->getAuthor()->getId()?->toRfc4122()) {
            return;
        }

        yield new ResolvedNotification(
            recipient: $reporter,
            type: NotificationType::FeedbackReply,
            titleKey: 'notification.feedback_reply',
            titleParams: ['%ticket%' => $task->getIdentifier() ?? ''],
            link: '/feedback/' . ($task->getId()?->toRfc4122() ?? ''),
            body: $this->excerpt($comment->getContent()),
        );
    }

    private function excerpt(string $text, int $max = 140): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
