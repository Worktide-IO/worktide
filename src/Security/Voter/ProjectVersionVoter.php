<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ProjectVersion;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ProjectVersion access defers to its Project — same pattern as
 * TaskListVoter / ProjectMilestoneVoter. Workspace admins can always
 * see/edit; external project members follow whatever the project's
 * own voter grants them.
 */
final class ProjectVersionVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ProjectVersion
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof ProjectVersion);
        if (!$token->getUser() instanceof User) {
            return false;
        }
        return $this->decisions->decide($token, [$attribute], $subject->getProject());
    }
}
