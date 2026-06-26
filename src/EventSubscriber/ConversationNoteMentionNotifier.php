<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ConversationNote;
use App\Entity\DomainEventLog;
use App\Entity\User;
use App\Service\MentionExtractor;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Emits `conversation.user_mentioned` for every user newly mentioned in a
 * {@see ConversationNote} body — the @-mention half of internal notes. Same
 * mechanics as {@see DocumentMentionNotifier}: detect new `/v1/users/<uuid>`
 * IRIs, skip self-mentions, persist the {@see DomainEventLog} in the same flush.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class ConversationNoteMentionNotifier
{
    public function __construct(
        private readonly Security $security,
        private readonly MentionExtractor $mentions,
    ) {}

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $note = $event->getObject();
        if (!$note instanceof ConversationNote) {
            return;
        }
        if (!$event->hasChangedField('body')) {
            return;
        }

        $new = array_values(array_diff(
            $this->mentions->iris((string) $event->getNewValue('body')),
            $this->mentions->iris((string) $event->getOldValue('body')),
        ));
        if ($new === []) {
            return;
        }

        $this->emitFor($note, $new, $event->getObjectManager());
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $note = $event->getObject();
        if (!$note instanceof ConversationNote) {
            return;
        }

        $mentions = $this->mentions->iris($note->getBody());
        if ($mentions === []) {
            return;
        }

        $this->emitFor($note, $mentions, $event->getObjectManager());
    }

    /**
     * @param list<string> $iris
     */
    private function emitFor(ConversationNote $note, array $iris, object $em): void
    {
        $actor = $this->security->getUser();
        $actorIri = $actor instanceof User
            ? '/v1/users/' . $actor->getId()?->toRfc4122()
            : null;

        $conversation = $note->getConversation();
        $workspace = $note->getWorkspace();

        foreach ($iris as $iri) {
            if ($actorIri !== null && $iri === $actorIri) {
                continue; // skip self-mentions
            }
            $event = new DomainEventLog(
                'conversation.user_mentioned',
                'Conversation',
                $conversation->getId(),
                $workspace,
                $actor instanceof User ? $actor : null,
                [
                    'conversationId' => $conversation->getId()?->toRfc4122(),
                    'conversationSubject' => $conversation->getSubject(),
                    'noteId' => $note->getId()?->toRfc4122(),
                    'mentionedUser' => $iri,
                ],
            );
            $em->persist($event);
            // Same flush as the note write, so consumers see them together.
            $meta = $em->getClassMetadata(DomainEventLog::class);
            $em->getUnitOfWork()->computeChangeSet($meta, $event);
        }
    }
}
