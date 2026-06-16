<?php

declare(strict_types=1);

namespace App\Service\Workflow;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use App\Repository\WorkflowTransitionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decides whether a given user is allowed to move a Task from its
 * current status to a candidate new status.
 *
 * The rules are intentionally permissive by default — a workspace that
 * has never authored a single WorkflowTransition row sees no change in
 * behaviour. Only workspaces that opt into a constrained workflow get
 * the gating treatment.
 *
 * See {@see \App\Entity\WorkflowTransition} for the data model and the
 * default-open semantics.
 */
final class WorkflowPolicy
{
    public function __construct(
        private readonly WorkflowTransitionRepository $transitions,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return true when allowed, otherwise a short reason string the
     *              SPA can surface in a toast.
     *
     * Pass `$fromStatus` explicitly — by the time a preUpdate listener
     * calls us, Doctrine has already swapped `$task->getStatus()` to
     * the new value, so we can't infer the source state from the
     * entity. Callers in non-listener contexts (manual policy queries
     * for SPA dropdowns) pass the same status as `$task->getStatus()`.
     */
    public function checkTransition(Task $task, TaskStatus $fromStatus, TaskStatus $newStatus, User $actor): true|string
    {
        $workspace = $task->getWorkspace();
        if ($workspace === null) {
            // Tasks with no workspace shouldn't reach here; allow rather
            // than break the save.
            return true;
        }

        if ($fromStatus->getId()?->equals($newStatus->getId() ?? new \Symfony\Component\Uid\NilUuid())) {
            return true; // not really a transition
        }

        // Workspace owners + admins bypass workflow rules so a broken
        // workflow doesn't lock the workspace into a corner.
        $memberRole = $this->roleFor($actor, $workspace);
        if ($memberRole === WorkspaceMemberRole::Owner || $memberRole === WorkspaceMemberRole::Admin) {
            return true;
        }

        $rows = $this->transitions->findFromStatusForTracker(
            $workspace,
            $task->getTracker(),
            $fromStatus,
        );
        if ($rows === []) {
            // No rules defined for this fromStatus → default-open: any
            // transition is allowed.
            return true;
        }

        $newStatusId = $newStatus->getId()?->toRfc4122();
        foreach ($rows as $row) {
            if ($row->getToStatus()->getId()?->toRfc4122() !== $newStatusId) {
                continue;
            }
            $allowed = $row->getAllowedRoles();
            if ($allowed === null) {
                return true; // null = any workspace member
            }
            if ($memberRole !== null && \in_array($memberRole->value, $allowed, true)) {
                return true;
            }
            return sprintf(
                'Diese Statusänderung ist nur für Rollen %s erlaubt.',
                implode(', ', $allowed),
            );
        }

        return sprintf(
            'Statuswechsel von „%s" zu „%s" ist im Workflow dieses Trackers nicht erlaubt.',
            $fromStatus->getName(),
            $newStatus->getName(),
        );
    }

    private function roleFor(User $user, \App\Entity\Workspace $workspace): ?WorkspaceMemberRole
    {
        $member = $this->em->getRepository(WorkspaceMember::class)
            ->findOneBy(['user' => $user, 'workspace' => $workspace]);
        return $member?->getRole();
    }
}
