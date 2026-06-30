<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Customer;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Customer access delegates to its Workspace — whoever may VIEW/EDIT/DELETE the
 * workspace may do the same to its customers. Same delegation pattern as
 * {@see SprintVoter} (→ Project). Needed so object-level checks like
 * `is_granted('VIEW', $customer)` resolve (e.g. attaching files to a customer
 * via the FileUploadController); the API resources themselves already authorise
 * against the workspace.
 */
final class CustomerVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Customer
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Customer);
        if (!$token->getUser() instanceof User) {
            return false;
        }

        return $this->decisions->decide($token, [$attribute], $subject->getWorkspace());
    }
}
