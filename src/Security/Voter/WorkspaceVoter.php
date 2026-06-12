<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Workspace-level authorisation:
 *  - VIEW:   any member of the workspace.
 *  - EDIT:   owner or admin role.
 *  - MANAGE: owner only.
 *  - DELETE: owner only.
 */
final class WorkspaceVoter extends Voter
{
    public function __construct(
        private readonly WorkspaceMemberRepository $members,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Workspace
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Workspace);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $membership = $this->members->findOneBy(['workspace' => $subject, 'user' => $user]);
        if ($membership === null) {
            return false;
        }
        $role = $membership->getRole();

        return match ($attribute) {
            WorktidePermission::VIEW   => true,
            WorktidePermission::EDIT   => \in_array($role, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true),
            WorktidePermission::MANAGE,
            WorktidePermission::DELETE => $role === WorkspaceMemberRole::Owner,
            default                    => false,
        };
    }
}
