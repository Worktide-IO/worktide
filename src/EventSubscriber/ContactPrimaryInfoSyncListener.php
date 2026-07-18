<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Contact;
use App\Entity\ContactEmail;
use App\Entity\ContactPhone;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps the legacy denormalized columns Contact.email / phone / mobile in sync
 * with the primary rows in {@see ContactEmail} / {@see ContactPhone}, so the
 * ~70 existing readers of those columns (portal login, newsletter, search,
 * lexoffice, form-prefill, ContactResolver fallback) keep working while the new
 * multi-value model is authoritative.
 *
 *  - email  ← primary ContactEmail (else first by createdAt)
 *  - phone  ← primary non-mobile ContactPhone (business/private/fax)
 *  - mobile ← primary ContactPhone with category = mobile
 *
 * COALESCE keeps the current column value when a contact has NO child rows of
 * that kind, so the legacy direct-write paths (CSV import, lexoffice sync that
 * still call Contact::setEmail) are never clobbered.
 *
 * onFlush collects the contacts touched by any email/phone change (insert,
 * update, delete — the child still carries its contact FK at that point);
 * postFlush issues the recompute once the child rows are committed.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ContactPrimaryInfoSyncListener
{
    /** @var array<string, Uuid> contact-id (rfc4122) => Uuid, deduped (forward: mirror ← children) */
    private array $pendingContactIds = [];

    /** @var array<string, Uuid> freshly-inserted contacts (reverse: children ← legacy columns) */
    private array $newContactIds = [];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        $changes = array_merge(
            $uow->getScheduledEntityInsertions(),
            $uow->getScheduledEntityUpdates(),
            $uow->getScheduledEntityDeletions(),
        );

        foreach ($changes as $entity) {
            if ($entity instanceof ContactEmail || $entity instanceof ContactPhone) {
                $contactId = $entity->getContact()->getId();
                if ($contactId !== null) {
                    $this->pendingContactIds[$contactId->toRfc4122()] = $contactId;
                }
            }
        }

        // Reverse sync only for brand-new contacts (insert), so a contact created
        // via the form / CSV / lexoffice with only the legacy email/phone/mobile
        // columns gets matching child rows. Insert-only avoids duplicating rows
        // when the legacy columns are edited later.
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Contact && $entity->getId() !== null) {
                $this->newContactIds[$entity->getId()->toRfc4122()] = $entity->getId();
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $conn = $args->getObjectManager()->getConnection();

        if ($this->newContactIds !== []) {
            $newIds = $this->newContactIds;
            $this->newContactIds = [];
            foreach ($newIds as $uuid) {
                $this->materializeChildrenFromLegacy($conn, $uuid->toBinary());
            }
        }

        if ($this->pendingContactIds === []) {
            return;
        }

        $ids = $this->pendingContactIds;
        $this->pendingContactIds = [];

        foreach ($ids as $uuid) {
            $bin = $uuid->toBinary();
            $conn->executeStatement(
                'UPDATE contacts SET email = COALESCE(
                    (SELECT ce.address FROM contact_emails ce WHERE ce.contact_id = :id
                       ORDER BY ce.is_primary DESC, ce.created_at ASC, ce.id ASC LIMIT 1),
                    email)
                 WHERE id = :id',
                ['id' => $bin],
            );
            $conn->executeStatement(
                'UPDATE contacts SET phone = COALESCE(
                    (SELECT cp.number FROM contact_phones cp WHERE cp.contact_id = :id AND cp.category <> :mobile
                       ORDER BY cp.is_primary DESC, cp.created_at ASC, cp.id ASC LIMIT 1),
                    phone)
                 WHERE id = :id',
                ['id' => $bin, 'mobile' => 'mobile'],
            );
            $conn->executeStatement(
                'UPDATE contacts SET mobile = COALESCE(
                    (SELECT cp.number FROM contact_phones cp WHERE cp.contact_id = :id AND cp.category = :mobile
                       ORDER BY cp.is_primary DESC, cp.created_at ASC, cp.id ASC LIMIT 1),
                    mobile)
                 WHERE id = :id',
                ['id' => $bin, 'mobile' => 'mobile'],
            );
        }
    }

    /**
     * Insert primary child rows for a new contact from its legacy columns, but
     * only when no matching child row exists yet (so a contact created WITH
     * child rows, e.g. via the inbox link-contact flow, is left untouched).
     */
    private function materializeChildrenFromLegacy(\Doctrine\DBAL\Connection $conn, string $bin): void
    {
        $conn->executeStatement(
            'INSERT INTO contact_emails (id, contact_id, workspace_id, address, is_primary, is_verified, created_at, updated_at)
             SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), c.id, c.workspace_id, LOWER(c.email), 1, 0, NOW(), NOW()
             FROM contacts c
             WHERE c.id = :id AND c.email IS NOT NULL AND c.email <> \'\'
               AND NOT EXISTS (SELECT 1 FROM contact_emails ce WHERE ce.contact_id = c.id)',
            ['id' => $bin],
        );
        $conn->executeStatement(
            'INSERT INTO contact_phones (id, contact_id, workspace_id, number, category, is_primary, created_at, updated_at)
             SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), c.id, c.workspace_id, c.phone, \'business\', 1, NOW(), NOW()
             FROM contacts c
             WHERE c.id = :id AND c.phone IS NOT NULL AND c.phone <> \'\'
               AND NOT EXISTS (SELECT 1 FROM contact_phones cp WHERE cp.contact_id = c.id AND cp.category <> \'mobile\')',
            ['id' => $bin],
        );
        $conn->executeStatement(
            'INSERT INTO contact_phones (id, contact_id, workspace_id, number, category, is_primary, created_at, updated_at)
             SELECT UNHEX(REPLACE(UUID(), \'-\', \'\')), c.id, c.workspace_id, c.mobile, \'mobile\', 1, NOW(), NOW()
             FROM contacts c
             WHERE c.id = :id AND c.mobile IS NOT NULL AND c.mobile <> \'\'
               AND NOT EXISTS (SELECT 1 FROM contact_phones cp WHERE cp.contact_id = c.id AND cp.category = \'mobile\')',
            ['id' => $bin],
        );
    }
}
