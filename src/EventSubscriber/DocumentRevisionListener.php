<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\Enum\DocumentWorkflowState;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Append a DocumentRevision snapshot whenever a Document's name or body
 * changes. The snapshot stores the PRE-CHANGE values so the history
 * list reads as "this was the state at <timestamp>" — restoring then
 * means copying revision.body back onto the document.
 *
 * Lifecycle hook: preUpdate fires AFTER Doctrine computed the change
 * set but BEFORE the UPDATE is executed; we still see both old and
 * new values via $event->getOldValue(). Using preUpdate also means a
 * failed flush rolls the revision back together with the document
 * change — no orphan revisions.
 *
 * Saves with no body/name change (e.g. a PATCH that only flips a
 * boolean flag) intentionally do not produce revisions; only changes
 * to the human-meaningful content are tracked.
 */
#[AsDoctrineListener(event: Events::preUpdate, priority: 0)]
final class DocumentRevisionListener
{
    public function __construct(
        private readonly Security $security,
    ) {}

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $doc = $event->getObject();
        if (!$doc instanceof Document) {
            return;
        }

        $changed = $event->hasChangedField('name') || $event->hasChangedField('body');
        if (!$changed) {
            return;
        }

        // Snapshot the *previous* state so the user can read history
        // in chronological order and restore the version they had
        // before the current save.
        $oldName = $event->hasChangedField('name')
            ? (string) $event->getOldValue('name')
            : $doc->getName();
        $oldBody = $event->hasChangedField('body')
            ? $event->getOldValue('body')
            : $doc->getBody();

        $revision = (new DocumentRevision())
            ->setDocument($doc)
            ->setName($oldName)
            ->setBody(is_string($oldBody) ? $oldBody : null)
            ->setBodyFormat($doc->getBodyFormat())
            ->setWorkspace($doc->getWorkspace());

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $revision->setAuthor($user);
        }

        // Persist + sync with current Unit of Work so it lands in the
        // same transaction as the document update.
        $em = $event->getObjectManager();
        $em->persist($revision);
        $meta = $em->getClassMetadata(DocumentRevision::class);
        $em->getUnitOfWork()->computeChangeSet($meta, $revision);

        // Editing a published document silently sends it back to draft —
        // a published page should always reflect the last approved
        // state, so the next save needs to go through the review cycle
        // again. The workflowState column isn't writable via PATCH, so
        // the only way to leave `published` is through here or the
        // workflow controller.
        if ($doc->getWorkflowState() === DocumentWorkflowState::Published) {
            $doc->setWorkflowState(DocumentWorkflowState::Draft);
            $docMeta = $em->getClassMetadata(Document::class);
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($docMeta, $doc);
        }
    }
}
