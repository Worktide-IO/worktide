<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\PublicFormSubmission;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A submission inherits the access of its form's project — whoever may VIEW the
 * project may read its submissions. Mirrors {@see PublicFormVoter}.
 */
final class PublicFormSubmissionVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof PublicFormSubmission
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof PublicFormSubmission);
        if (!$token->getUser() instanceof User) {
            return false;
        }

        return $this->decisions->decide($token, [$attribute], $subject->getForm()->getProject());
    }
}
