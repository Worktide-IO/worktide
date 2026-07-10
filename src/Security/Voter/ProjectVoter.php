<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\ProjectMemberRole;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectShareRepository;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Project-level authorisation. Implements the cross-agency collaboration
 * pattern: a user can see/edit a project they were explicitly added to as a
 * ProjectMember, even when they are NOT a member of the host workspace.
 *
 * Resolution order (first match wins):
 *  1. Workspace owner/admin                              → MANAGE / DELETE / EDIT / VIEW
 *  2. Workspace member                                   → EDIT / VIEW
 *  2b. Workspace guest (read-mostly)                     → VIEW only
 *  3. ProjectMember on this specific project (manager)   → MANAGE / EDIT / VIEW
 *  4. ProjectMember (contributor)                        → EDIT / VIEW
 *  5. ProjectMember (viewer)                             → VIEW only
 *  6. otherwise                                          → denied
 */
final class ProjectVoter extends Voter
{
    public function __construct(
        private readonly WorkspaceMemberRepository $wsMembers,
        private readonly ProjectMemberRepository $projectMembers,
        private readonly ProjectShareRepository $projectShares,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Project
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Project);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $wsRole = $this->wsMembers->findOneBy([
            'workspace' => $subject->getWorkspace(),
            'user' => $user,
        ])?->getRole();

        if ($wsRole !== null) {
            return match ($attribute) {
                WorktidePermission::VIEW   => true,
                // Guests are read-mostly (see DefaultPermissions) — no project
                // EDIT, which would otherwise cascade to task create/edit.
                WorktidePermission::EDIT   => $wsRole !== WorkspaceMemberRole::Guest,
                WorktidePermission::MANAGE,
                WorktidePermission::DELETE => \in_array($wsRole, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true),
                default                    => false,
            };
        }

        // ProjectMember on this project, OR — for cross-workspace sharing — the
        // role granted by an accepted ProjectShare into a workspace the user
        // belongs to. Same role→permission mapping either way.
        $projectRole = $this->projectMembers->findOneBy([
            'project' => $subject,
            'user' => $user,
        ])?->getRole()
            ?? $this->projectShares->findRoleForUser($subject, $user);

        if ($projectRole === null) {
            return false;
        }

        return match ($attribute) {
            WorktidePermission::VIEW   => true,
            WorktidePermission::EDIT   => \in_array($projectRole, [ProjectMemberRole::Manager, ProjectMemberRole::Contributor], true),
            WorktidePermission::MANAGE,
            WorktidePermission::DELETE => $projectRole === ProjectMemberRole::Manager,
            default                    => false,
        };
    }
}
