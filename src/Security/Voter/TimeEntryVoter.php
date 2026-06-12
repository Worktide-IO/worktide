<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Time entry access:
 *  - Author of the entry → always full access, except DELETE when locked.
 *  - Workspace owner/admin → full access on any entry in the workspace.
 *  - Anyone else → denied.
 *
 * Once Phase 2 lands a Time-Entry-Approval workflow, "locked" entries become
 * read-only to authors and only managers can re-open them.
 */
final class TimeEntryVoter extends Voter
{
    public function __construct(
        private readonly WorkspaceMemberRepository $wsMembers,
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

        $isOwn = $subject->getUser()->getId()?->equals($user->getId()) === true;
        if ($isOwn) {
            if ($attribute === WorktidePermission::DELETE && $subject->isLocked()) {
                return false;
            }
            return true;
        }

        $wsRole = $this->wsMembers->findOneBy([
            'workspace' => $subject->getWorkspace(),
            'user' => $user,
        ])?->getRole();

        return \in_array($wsRole, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true);
    }
}
