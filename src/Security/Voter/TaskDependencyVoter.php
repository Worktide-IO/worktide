<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\TaskDependency;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Task dependencies inherit access from the predecessor task; if the user
 * can VIEW/EDIT the predecessor (and by extension its project), they can
 * see/manage dependency relations from it.
 */
final class TaskDependencyVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof TaskDependency
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof TaskDependency);
        if (!$token->getUser() instanceof User) {
            return false;
        }
        return $this->decisions->decide($token, [$attribute], $subject->getPredecessor());
    }
}
