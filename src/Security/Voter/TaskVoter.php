<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Enum\Capability;
use App\Entity\Task;
use App\Entity\User;
use App\Security\PermissionResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A task's access is derived from its project:
 *  VIEW   = VIEW on the project
 *  EDIT   = EDIT on the project (assignees can always edit their own task)
 *  DELETE = EDIT on the project AND the granular `task.delete_*` capability,
 *           split into _own vs _others by whether the user created the task
 *  MANAGE = MANAGE on the project
 *
 * The fine-grained capability check (B11) is what lets a workspace owner say
 * "Members may not delete other people's tasks" without rewriting the voter.
 */
final class TaskVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly PermissionResolver $permissions,
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

        // Any assignee can always view + edit their own task.
        if ($attribute === WorktidePermission::VIEW || $attribute === WorktidePermission::EDIT) {
            foreach ($subject->getAssignees() as $assignee) {
                if ($assignee->getId()?->equals($user->getId())) {
                    return true;
                }
            }
        }

        $projectAttr = $attribute === WorktidePermission::DELETE
            ? WorktidePermission::EDIT
            : $attribute;

        $projectAllows = $this->decisions->decide($token, [$projectAttr], $subject->getProject());
        if (!$projectAllows) {
            return false;
        }

        if ($attribute === WorktidePermission::DELETE) {
            $isOwn = $subject->getCreatedBy()?->getId()?->equals($user->getId()) === true;
            $capability = $isOwn ? Capability::TaskDeleteOwn : Capability::TaskDeleteOthers;
            return $this->permissions->can($user, $capability, $subject->getWorkspace());
        }

        return true;
    }
}
