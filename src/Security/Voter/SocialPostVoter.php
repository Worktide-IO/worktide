<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\SocialPost;
use App\Entity\SocialPostTarget;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Social-post access, mirroring {@see ConversationNoteVoter}:
 *   - VIEW   → any workspace member
 *   - EDIT   → the author, or anyone with workspace EDIT
 *   - DELETE → the author, or anyone with workspace EDIT
 *   - MANAGE → workspace MANAGE (gates approve / publish-now / retry — the
 *              external-publishing actions, human-in-the-loop)
 *
 * Handles both {@see SocialPost} and {@see SocialPostTarget} (the latter
 * resolves through its parent post) so the target's read + retry endpoints can
 * reuse one voter.
 */
final class SocialPostVoter extends Voter
{
    public function __construct(
        private readonly AccessDecisionManagerInterface $decisions,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return ($subject instanceof SocialPost || $subject instanceof SocialPostTarget)
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $post = $subject instanceof SocialPostTarget ? $subject->getSocialPost() : $subject;
        \assert($post instanceof SocialPost);

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $workspace = $post->getWorkspace();

        if ($attribute === WorktidePermission::VIEW) {
            return $this->decisions->decide($token, [WorktidePermission::VIEW], $workspace);
        }

        if ($attribute === WorktidePermission::MANAGE) {
            return $this->decisions->decide($token, [WorktidePermission::MANAGE], $workspace);
        }

        // EDIT / DELETE: author shortcut, else workspace EDIT.
        if ($post->getCreatedByUser() === $user) {
            return true;
        }

        return $this->decisions->decide($token, [WorktidePermission::EDIT], $workspace);
    }
}
