<?php

declare(strict_types=1);

namespace App\Notification\Resolver;

use App\Entity\DomainEventLog;
use App\Entity\Enum\NotificationType;
use App\Notification\NotificationResolverInterface;
use App\Notification\RecipientResolver;
use App\Notification\ResolvedNotification;

/**
 * @mention in a document or conversation note.
 *
 * {@see \App\EventSubscriber\DocumentMentionNotifier} and
 * {@see \App\EventSubscriber\ConversationNoteMentionNotifier} emit one event
 * per newly-mentioned user, with the mentioned user's IRI in the payload.
 * Staff-only context → staff SPA links.
 */
final class MentionResolver implements NotificationResolverInterface
{
    public function __construct(
        private readonly RecipientResolver $recipients,
    ) {}

    public function supports(DomainEventLog $event): bool
    {
        return \in_array($event->getName(), ['document.user_mentioned', 'conversation.user_mentioned'], true);
    }

    public function resolve(DomainEventLog $event): iterable
    {
        $payload = $event->getPayload();
        $iri = $payload['mentionedUser'] ?? null;
        if (!\is_string($iri)) {
            return;
        }
        $recipient = $this->recipients->userFromIri($iri);
        if ($recipient === null) {
            return;
        }
        // Never notify the actor about their own mention edit.
        if ($event->getActor() !== null && $recipient->getId()?->toRfc4122() === $event->getActor()->getId()?->toRfc4122()) {
            return;
        }

        if ($event->getName() === 'conversation.user_mentioned') {
            $subject = \is_string($payload['conversationSubject'] ?? null) ? $payload['conversationSubject'] : 'einer Unterhaltung';
            $convId = \is_string($payload['conversationId'] ?? null) ? $payload['conversationId'] : '';
            yield new ResolvedNotification(
                recipient: $recipient,
                type: NotificationType::Mention,
                titleKey: 'notification.mention',
                titleParams: ['%subject%' => $subject],
                link: $convId !== '' ? '/inbox/' . $convId : '/inbox',
            );

            return;
        }

        $docName = \is_string($payload['documentName'] ?? null) ? $payload['documentName'] : 'einem Dokument';
        yield new ResolvedNotification(
            recipient: $recipient,
            type: NotificationType::Mention,
            titleKey: 'notification.mention',
            titleParams: ['%subject%' => $docName],
            link: '/documents',
        );
    }
}
