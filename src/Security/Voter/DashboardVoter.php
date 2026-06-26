<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Dashboard;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Dashboard access: any workspace member may VIEW a workspace's dashboards; the
 * creator (or a workspace admin/owner) may EDIT/DELETE. Membership + admin
 * checks delegate to {@see WorkspaceVoter} via the access-decision manager.
 */
final class DashboardVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Dashboard
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Dashboard);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $workspace = $subject->getWorkspace();

        if ($attribute === WorktidePermission::VIEW) {
            // Any member of the workspace.
            return $this->decisions->decide($token, [WorktidePermission::VIEW], $workspace);
        }

        // EDIT / DELETE / MANAGE: the creator, or a workspace admin/owner.
        if ($subject->getCreatedByUser() === $user) {
            return true;
        }

        return $this->decisions->decide($token, [WorktidePermission::EDIT], $workspace);
    }
}
