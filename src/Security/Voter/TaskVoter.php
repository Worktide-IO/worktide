<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Task;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A task's access is derived from its project:
 *  VIEW   = VIEW on the project
 *  EDIT   = EDIT on the project (assignees can always edit their own task)
 *  DELETE = EDIT on the project
 *  MANAGE = MANAGE on the project
 */
final class TaskVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Task
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Task);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Assignee can always view + edit their own task.
        if (
            ($attribute === WorktidePermission::VIEW || $attribute === WorktidePermission::EDIT)
            && $subject->getAssignee()?->getId()?->equals($user->getId())
        ) {
            return true;
        }

        $projectAttr = $attribute === WorktidePermission::DELETE
            ? WorktidePermission::EDIT
            : $attribute;

        return $this->decisions->decide($token, [$projectAttr], $subject->getProject());
    }
}
