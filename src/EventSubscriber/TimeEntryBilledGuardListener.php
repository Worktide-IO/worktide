<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Enum\Capability;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Security\PermissionResolver;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Field-level guard for {@see TimeEntry::$isBilled}.
 *
 * The generic API Platform `Patch` operation is gated by the EDIT
 * voter (= `time_entry.update_own` for the author), which lets an
 * author edit duration/note on their own entries. Flipping the
 * accounting-relevant "billed" flag is split off into its own
 * capability so a workspace can keep self-service time editing while
 * reserving billed-status changes for finance/admins — without taking
 * away update_own entirely.
 *
 * Enforced here (rather than in the voter) because the voter sees the
 * whole object, not the change-set — it can't tell a note edit from a
 * billed-status flip. preUpdate carries the exact changed fields, and
 * this one spot covers BOTH write paths (generic PATCH and the
 * BatchOperationsController, which lists isBilled as settable).
 *
 * Throwing {@see AccessDeniedException} during flush bubbles up to the
 * Symfony firewall, which maps it to a 403 just like a voter denial.
 */
#[AsDoctrineListener(event: Events::preUpdate, priority: -40)]
final class TimeEntryBilledGuardListener
{
    public function __construct(
        private readonly Security $security,
        private readonly PermissionResolver $permissions,
    ) {}

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof TimeEntry) {
            return;
        }
        if (!$event->hasChangedField('isBilled')) {
            return;
        }

        // No authenticated user → system/CLI write (e.g. a future
        // billing-run auto-setting isBilled when a TimeEntry is mapped
        // to an Invoice). Those are trusted and pass through.
        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            return;
        }

        $isOwn = $entity->getUser()->getId()?->equals($actor->getId()) === true;
        if (!$isOwn) {
            // A non-author needed time_entry.update_others to reach this
            // PATCH at all (admin-ish) — let the billed change ride along.
            return;
        }

        // Locked entries are finalised; the billed flag must not move
        // even for the author. The UI disables the toggle too.
        if ($entity->isLocked()) {
            throw new AccessDeniedException('Locked time entries cannot change their billed status.');
        }

        if (!$this->permissions->can($actor, Capability::TimeEntryToggleBilledOwn, $entity->getWorkspace())) {
            throw new AccessDeniedException('You may not change the billed status of your time entries in this workspace.');
        }
    }
}
