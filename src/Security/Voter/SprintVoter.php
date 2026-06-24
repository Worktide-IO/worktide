<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Sprint;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Sprint access mirrors its parent Project — same delegation as
 * {@see TaskListVoter}: whoever may VIEW/EDIT/DELETE the project may do the
 * same to its sprints.
 */
final class SprintVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Sprint
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Sprint);
        if (!$token->getUser() instanceof User) {
            return false;
        }

        return $this->decisions->decide($token, [$attribute], $subject->getProject());
    }
}
