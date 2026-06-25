<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ProjectStatusUpdate;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A status update inherits the access of its project — same delegation as
 * {@see ProjectMilestoneVoter} / {@see SprintVoter}: whoever may VIEW/EDIT/DELETE
 * the project may do the same to its status updates.
 */
final class ProjectStatusUpdateVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ProjectStatusUpdate
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof ProjectStatusUpdate);
        if (!$token->getUser() instanceof User) {
            return false;
        }

        return $this->decisions->decide($token, [$attribute], $subject->getProject());
    }
}
