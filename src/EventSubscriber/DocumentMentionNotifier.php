<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Document;
use App\Entity\DomainEventLog;
use App\Entity\User;
use App\Service\MentionExtractor;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Emits a `document.user_mentioned` domain event for every user that is
 * newly mentioned in a Document's body after a save.
 *
 * Detection: BlockNote serialises mentions as inline-content nodes of
 * type "mention" with a userIri prop. We don't try to parse the whole
 * JSON tree — a substring scan over the body for the IRI of each
 * workspace user finds them all (UUIDs are unique enough that
 * collisions don't happen). The diff (set(new) - set(old)) is what
 * gets notified; existing mentions don't re-fire on every save.
 *
 * Self-mentions are silently dropped — pinging the author of a save
 * is noise. The actor on the event is the saver; the aggregate is the
 * document.
 *
 * Downstream consumers (Mercure broadcast, an upcoming Notification
 * entity, an email digest) can subscribe to the DomainEventLog by
 * filtering on name='document.user_mentioned'.
 */
#[AsDoctrineListener(event: Events::preUpdate, priority: -10)]
#[AsDoctrineListener(event: Events::postPersist, priority: -10)]
final class DocumentMentionNotifier
{
    public function __construct(
        private readonly Security $security,
        private readonly MentionExtractor $mentions,
    ) {}

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $doc = $event->getObject();
        if (!$doc instanceof Document) {
            return;
        }
        if (!$event->hasChangedField('body')) {
            return;
        }
        $oldBody = (string) ($event->getOldValue('body') ?? '');
        $newBody = (string) ($event->getNewValue('body') ?? '');
        $newMentions = array_values(array_diff(
            $this->mentions->iris($newBody),
            $this->mentions->iris($oldBody),
        ));
        if (empty($newMentions)) {
            return;
        }
        $this->emitFor($doc, $newMentions, $event->getObjectManager());
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $doc = $event->getObject();
        if (!$doc instanceof Document) {
            return;
        }
        $newMentions = $this->mentions->iris((string) $doc->getBody());
        if (empty($newMentions)) {
            return;
        }
        $this->emitFor($doc, $newMentions, $event->getObjectManager());
    }

    /**
     * @param list<string> $iris
     */
    private function emitFor(Document $doc, array $iris, object $em): void
    {
        $actor = $this->security->getUser();
        $actorIri = $actor instanceof User
            ? '/v1/users/' . $actor->getId()?->toRfc4122()
            : null;

        $aggregateType = 'Document';
        $aggregateId = $doc->getId();
        $workspace = $doc->getWorkspace();

        foreach ($iris as $iri) {
            // Skip self-mentions — pinging the author of the save is
            // noise, especially during iterative edits.
            if ($actorIri !== null && $iri === $actorIri) {
                continue;
            }
            $payload = [
                'documentId' => $aggregateId?->toRfc4122(),
                'documentName' => $doc->getName(),
                'mentionedUser' => $iri,
            ];
            $event = new DomainEventLog(
                'document.user_mentioned',
                $aggregateType,
                $aggregateId,
                $workspace,
                $actor instanceof User ? $actor : null,
                $payload,
            );
            $em->persist($event);
            // Make Doctrine pick it up in the SAME flush as the
            // document update so notification-consumers see them
            // together.
            $meta = $em->getClassMetadata(DomainEventLog::class);
            $em->getUnitOfWork()->computeChangeSet($meta, $event);
        }
    }
}
