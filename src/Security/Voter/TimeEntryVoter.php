<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\Capability;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\WorkspaceMemberRepository;
use App\Security\PermissionResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Time entry access:
 *  - Author of the entry → grants depend on the `time_entry.*_own` matrix
 *    capabilities (so a workspace can revoke e.g. delete-own without
 *    touching others). Locked entries are always undeletable.
 *  - Non-author with the matching `time_entry.*_others` capability → allowed.
 *  - Workspace owner short-circuits via PermissionResolver.
 *
 * Once Phase 2 lands a Time-Entry-Approval workflow, "locked" entries become
 * read-only to authors and only managers can re-open them.
 */
final class TimeEntryVoter extends Voter
{
    public function __construct(
        private readonly WorkspaceMemberRepository $wsMembers,
        private readonly PermissionResolver $permissions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof TimeEntry
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof TimeEntry);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $workspace = $subject->getWorkspace();
        $isOwn = $subject->getUser()->getId()?->equals($user->getId()) === true;

        if ($isOwn) {
            if ($attribute === WorktidePermission::DELETE && $subject->isLocked()) {
                return false;
            }
            return match ($attribute) {
                WorktidePermission::VIEW => true,
                WorktidePermission::EDIT => $this->permissions->can($user, Capability::TimeEntryUpdateOwn, $workspace),
                WorktidePermission::DELETE => $this->permissions->can($user, Capability::TimeEntryDeleteOwn, $workspace),
                WorktidePermission::MANAGE => true,
                default => false,
            };
        }

        $wsRole = $this->wsMembers->findOneBy([
            'workspace' => $workspace,
            'user' => $user,
        ])?->getRole();
        if ($wsRole === null) {
            return false;
        }
        // Workspace owner can see everything; let the matrix decide for the rest.
        if ($wsRole === WorkspaceMemberRole::Owner) {
            return true;
        }
        return match ($attribute) {
            WorktidePermission::VIEW => true,
            WorktidePermission::EDIT => $this->permissions->can($user, Capability::TimeEntryUpdateOthers, $workspace),
            WorktidePermission::DELETE => $this->permissions->can($user, Capability::TimeEntryDeleteOthers, $workspace),
            WorktidePermission::MANAGE => $wsRole === WorkspaceMemberRole::Admin,
            default => false,
        };
    }
}
