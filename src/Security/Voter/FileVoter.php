<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\FileTarget;
use App\Entity\File;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\CommentRepository;
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
 * Files inherit access from the entity they're attached to. Hidden-for-
 * connect-users follows the same external-collaborator rule as Comment.
 *
 * Files attached to a Document delegate to DocumentVoter via the AccessDecisionManager.
 */
final class FileVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly WorkspaceRepository $workspaces,
        private readonly UserRepository $users,
        private readonly CommentRepository $comments,
        private readonly DocumentRepository $documents,
        private readonly WorkspaceMemberRepository $wsMembers,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof File
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof File);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $targetEntity = $this->resolveTarget($subject);
        if ($targetEntity === null) {
            return false;
        }

        // User-target files: only the user themselves, or workspace admin.
        if ($targetEntity instanceof User) {
            if ($targetEntity->getId()?->equals($user->getId())) {
                return true;
            }
            return $this->isWorkspaceMember($subject->getWorkspace(), $user);
        }

        $canView = $this->decisions->decide($token, [WorktidePermission::VIEW], $targetEntity);
        if (!$canView) {
            return false;
        }

        if ($subject->isHiddenForConnectUsers() && !$this->isWorkspaceMember($subject->getWorkspace(), $user)) {
            return false;
        }

        if ($attribute === WorktidePermission::VIEW) {
            return true;
        }

        // Mutation: uploader can always edit/delete their own file; otherwise
        // need EDIT on the parent target.
        $isUploader = $subject->getUploadedBy()?->getId()?->equals($user->getId()) === true;
        if ($isUploader) {
            return true;
        }
        return $this->decisions->decide($token, [WorktidePermission::EDIT], $targetEntity);
    }

    private function resolveTarget(File $file): ?object
    {
        return match ($file->getTarget()) {
            FileTarget::Project => $this->projects->find($file->getTargetId()),
            FileTarget::Task => $this->tasks->find($file->getTargetId()),
            FileTarget::Workspace => $this->workspaces->find($file->getTargetId()),
            FileTarget::User => $this->users->find($file->getTargetId()),
            FileTarget::Comment => $this->comments->find($file->getTargetId()),
            FileTarget::Document => $this->documents->find($file->getTargetId()),
        };
    }

    private function isWorkspaceMember(Workspace $workspace, User $user): bool
    {
        return $this->wsMembers->findOneBy(['workspace' => $workspace, 'user' => $user]) !== null;
    }
}
