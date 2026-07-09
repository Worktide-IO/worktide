<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\AIRecommendation;
use App\Entity\DomainEventLog;
use App\Entity\Enum\NotificationType;
use App\Entity\Enum\RecommendationTarget;
use App\Notification\NotificationResolverInterface;
use App\Notification\RecipientResolver;
use App\Notification\ResolvedNotification;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A new AI recommendation → notify the people responsible for its target.
 *
 * AIRecommendation has no owner field, so v1 routes task-scoped
 * recommendations to that task's assignees (the staff who'd act on the
 * suggestion). Other target kinds (conversation/customer/…) are skipped until
 * their audience is defined. Staff SPA link to the KI-Agenten overview.
 */
final class AiRecommendationResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskRepository $tasks,
        private readonly RecipientResolver $recipients,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return $event->getName() === 'airecommendation.created';
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $id = $event->getAggregateId();
        $rec = $id !== null ? $this->em->find(AIRecommendation::class, $id) : null;
        if (!$rec instanceof AIRecommendation || $rec->getTarget() !== RecommendationTarget::Task) {
            return;
        }
        $task = $this->tasks->find($rec->getTargetId());
        if ($task === null) {
            return;
        }

        $body = 'Vorschlag: ' . $rec->getKind()->value;
        foreach ($task->getAssignees() as $iri) {
            if (!\is_string($iri)) {
                continue;
            }
            $recipient = $this->recipients->userFromIri($iri);
            if ($recipient === null) {
                continue;
            }
            yield new ResolvedNotification(
                recipient: $recipient,
                type: NotificationType::Ai,
                title: 'Neue KI-Empfehlung für ' . $task->getIdentifier(),
                link: '/ki-agenten',
                body: $body,
            );
        }
    }
}
