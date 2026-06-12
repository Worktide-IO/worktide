<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\Enum\CommentTarget;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Comment-level access:
 *  VIEW   = the user can VIEW the target (delegated to ProjectVoter / TaskVoter).
 *  EDIT   = author of the comment, or VIEW + workspace EDIT on target.
 *  DELETE = author of the comment, or VIEW + workspace EDIT on target.
 *  MANAGE = same as EDIT (used for pin/unpin operations).
 *
 * Document target is recognised but voting is denied until the Document entity
 * lands in block B9.
 */
final class CommentVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly WorkspaceMemberRepository $wsMembers,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Comment
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Comment);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $targetEntity = $this->resolveTarget($subject);
        if ($targetEntity === null) {
            return false;
        }

        $canView = $this->decisions->decide($token, [WorktidePermission::VIEW], $targetEntity);
        if (!$canView) {
            return false;
        }

        // Hidden-for-connect-users: external (cross-workspace) ProjectMembers
        // don't get to see this comment at all. Only true workspace members do.
        if ($subject->isHiddenForConnectUsers()) {
            $isWorkspaceMember = $this->wsMembers->findOneBy([
                'workspace' => $subject->getWorkspace(),
                'user' => $user,
            ]) !== null;
            if (!$isWorkspaceMember) {
                return false;
            }
        }

        if ($attribute === WorktidePermission::VIEW) {
            return true;
        }

        $isAuthor = $subject->getAuthor()->getId()?->equals($user->getId()) === true;
        if ($isAuthor) {
            return true;
        }

        // Non-author: need EDIT on the underlying target (workspace admin level).
        return $this->decisions->decide($token, [WorktidePermission::EDIT], $targetEntity);
    }

    private function resolveTarget(Comment $comment): ?object
    {
        return match ($comment->getTarget()) {
            CommentTarget::Project => $this->projects->find($comment->getTargetId()),
            CommentTarget::Task => $this->tasks->find($comment->getTargetId()),
            CommentTarget::Document => null,
        };
    }
}
