<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\PublicForm;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * PublicForm access mirrors its target Project — same delegation as
 * {@see SprintVoter}: whoever may VIEW/EDIT/DELETE the project may do the same
 * to its public forms. (The anonymous /v1/forms/{slug} routes bypass voters
 * entirely; this voter only guards the authenticated /v1/public_forms CRUD.)
 */
final class PublicFormVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof PublicForm
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof PublicForm);
        if (!$token->getUser() instanceof User) {
            return false;
        }

        return $this->decisions->decide($token, [$attribute], $subject->getProject());
    }
}
