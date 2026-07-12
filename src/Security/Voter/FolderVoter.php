<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\FileTarget;
use App\Entity\Folder;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\CommentRepository;
use App\Repository\CustomerRepository;
use App\Repository\DocumentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Repository\WorkspaceRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Folders inherit access from the entity they're attached to (same polymorphic
 * model as {@see FileVoter}). Hidden-for-connect-users follows the same
 * external-collaborator rule; the folder's creator may always edit/delete their
 * own folder, otherwise EDIT on the parent target is required.
 */
final class FolderVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly WorkspaceRepository $workspaces,
        private readonly UserRepository $users,
        private readonly CommentRepository $comments,
        private readonly DocumentRepository $documents,
        private readonly CustomerRepository $customers,
        private readonly WorkspaceMemberRepository $wsMembers,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Folder
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Folder);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $targetEntity = $this->resolveTarget($subject);
        if ($targetEntity === null) {
            return false;
        }

        if (!$this->decisions->decide($token, [WorktidePermission::VIEW], $targetEntity)) {
            return false;
        }

        if ($subject->isHiddenForConnectUsers() && !$this->isWorkspaceMember($subject->getWorkspace(), $user)) {
            return false;
        }

        if ($attribute === WorktidePermission::VIEW) {
            return true;
        }

        // Mutation: creator can always edit/delete their own folder; otherwise
        // need EDIT on the parent target.
        if ($subject->getCreatedByUser()?->getId()?->equals($user->getId()) === true) {
            return true;
        }

        return $this->decisions->decide($token, [WorktidePermission::EDIT], $targetEntity);
    }

    private function resolveTarget(Folder $folder): ?object
    {
        return match ($folder->getTarget()) {
            FileTarget::Project => $this->projects->find($folder->getTargetId()),
            FileTarget::Task => $this->tasks->find($folder->getTargetId()),
            FileTarget::Workspace => $this->workspaces->find($folder->getTargetId()),
            FileTarget::User => $this->users->find($folder->getTargetId()),
            FileTarget::Comment => $this->comments->find($folder->getTargetId()),
            FileTarget::Document => $this->documents->find($folder->getTargetId()),
            FileTarget::Customer => $this->customers->find($folder->getTargetId()),
        };
    }

    private function isWorkspaceMember(Workspace $workspace, User $user): bool
    {
        return $this->wsMembers->findOneBy(['workspace' => $workspace, 'user' => $user]) !== null;
    }
}
