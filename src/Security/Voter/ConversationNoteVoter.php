<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ConversationNote;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Internal-note access: any workspace member may VIEW; the author or a
 * workspace admin/owner may EDIT/DELETE. Mirrors {@see DashboardVoter} —
 * membership/admin checks delegate to {@see WorkspaceVoter}.
 */
final class ConversationNoteVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ConversationNote
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof ConversationNote);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $workspace = $subject->getWorkspace();

        if ($attribute === WorktidePermission::VIEW) {
            return $this->decisions->decide($token, [WorktidePermission::VIEW], $workspace);
        }

        if ($subject->getCreatedByUser() === $user) {
            return true;
        }

        return $this->decisions->decide($token, [WorktidePermission::EDIT], $workspace);
    }
}
