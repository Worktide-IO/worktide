<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\Enum\DocumentAccess;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\DocumentContributorRepository;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Document access resolution:
 *  1. Explicit DocumentContributor row wins:
 *       - access=read    → VIEW only
 *       - access=manage  → VIEW + EDIT + DELETE + MANAGE
 *  2. Author (createdByUser) → MANAGE on their own document
 *  3. Workspace owner/admin → MANAGE / DELETE / EDIT / VIEW
 *  4. Workspace member (any role):
 *       - private doc          → denied (must be contributor)
 *       - non-private doc      → VIEW + EDIT (delete/manage stays admin-only)
 *  5. ProjectMember (cross-agency) — only when the doc is linked to a project
 *     they can see. Falls back to project-level VIEW from ProjectVoter and the
 *     same hidden-for-connect-users gating as Comment/File. Manage/delete stays
 *     denied for non-workspace-members.
 *  6. otherwise → denied
 *
 * isHiddenForConnectUsers blocks non-workspace-members (project guests) even
 * when they would otherwise inherit project visibility.
 */
final class DocumentVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly WorkspaceMemberRepository $wsMembers,
        private readonly DocumentContributorRepository $contributors,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Document
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Document);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $contribution = $this->contributors->findContribution($subject, $user);
        if ($contribution !== null) {
            return match ($attribute) {
                WorktidePermission::VIEW => true,
                WorktidePermission::EDIT,
                WorktidePermission::DELETE,
                WorktidePermission::MANAGE => $contribution->getAccess() === DocumentAccess::Manage,
                default => false,
            };
        }

        if ($subject->getCreatedByUser()?->getId()?->equals($user->getId()) === true) {
            return true;
        }

        $wsRole = $this->wsMembers->findOneBy([
            'workspace' => $subject->getWorkspace(),
            'user' => $user,
        ])?->getRole();

        if ($wsRole !== null) {
            $isAdmin = \in_array($wsRole, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true);
            if ($isAdmin) {
                return true;
            }
            if ($subject->isPrivate()) {
                return false;
            }
            return match ($attribute) {
                WorktidePermission::VIEW, WorktidePermission::EDIT => true,
                default => false,
            };
        }

        if ($subject->isPrivate() || $subject->isHiddenForConnectUsers()) {
            return false;
        }

        $project = $subject->getProject();
        if ($project === null) {
            return false;
        }

        $canViewProject = $this->decisions->decide($token, [WorktidePermission::VIEW], $project);
        if (!$canViewProject) {
            return false;
        }
        return $attribute === WorktidePermission::VIEW;
    }
}
