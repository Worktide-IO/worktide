<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Conversation;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Item-level access to a {@see Conversation}, following its mailbox (Channel):
 *  - personal mailbox: only the owner (channel.ownerUser), plus workspace
 *    owners/admins;
 *  - shared mailbox: any internal member (owner/admin/member — guests excluded).
 *
 * Mirrors {@see \App\ApiPlatform\Doctrine\MailboxVisibilityExtension} for custom
 * operations (create-task, ai-triage) where the query extension isn't the gate.
 * Visibility == may act, for all WorktidePermission attributes (finer per-action
 * rules can come later).
 */
final class ConversationVoter extends Voter
{
    public function __construct(
        private readonly WorkspaceMemberRepository $members,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Conversation
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Conversation);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $channel = $subject->getChannel();

        // Personal mailbox owner sees/acts on their own mailbox.
        if ($channel->getOwnerUser() === $user) {
            return true;
        }

        $member = $this->members->findOneBy([
            'workspace' => $channel->getWorkspace(),
            'user' => $user,
        ]);
        if ($member === null) {
            return false;
        }
        $role = $member->getRole();

        // Workspace owners/admins see everything, including personal mailboxes.
        if (\in_array($role, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true)) {
            return true;
        }

        // Shared mailbox: any internal member (guests excluded).
        return $channel->isShared()
            && \in_array($role, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin, WorkspaceMemberRole::Member], true);
    }
}
