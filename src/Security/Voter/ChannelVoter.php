<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Channel;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Repository\WorkspaceMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Access control for {@see Channel} (mailboxes / sync sources), by mailbox type:
 *
 *  - personal mailbox (isShared = false): the owner (channel.ownerUser) manages
 *    it fully; workspace owners/admins may manage it too.
 *  - shared mailbox (isShared = true): every internal member may VIEW it, but
 *    only workspace owners/admins may EDIT/DELETE/MANAGE it.
 *
 * Guests never get access. Wired onto the Channel API operations
 * (see {@see Channel} #[ApiResource]); for create/update the check runs
 * post-denormalization, so the RESULTING isShared/ownerUser state is what's
 * evaluated — a plain member therefore cannot create a shared channel, nor
 * escalate their own personal mailbox to shared.
 */
final class ChannelVoter extends Voter
{
    public function __construct(
        private readonly WorkspaceMemberRepository $members,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Channel
            && \in_array($attribute, WorktidePermission::ALL, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        \assert($subject instanceof Channel);
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Personal mailbox: the owner has full control over their own mailbox.
        if (!$subject->isShared() && $subject->getOwnerUser() === $user) {
            return true;
        }

        $member = $this->members->findOneBy([
            'workspace' => $subject->getWorkspace(),
            'user' => $user,
            'isActive' => true,
        ]);
        if ($member === null) {
            return false;
        }
        $role = $member->getRole();
        $isAdmin = \in_array($role, [WorkspaceMemberRole::Owner, WorkspaceMemberRole::Admin], true);

        // Workspace owners/admins may manage any channel (shared or personal).
        if ($isAdmin) {
            return true;
        }

        // Non-admins only get access to shared mailboxes, and only to VIEW —
        // managing a shared (team) channel is reserved for owners/admins.
        if (!$subject->isShared()) {
            return false;
        }

        return $attribute === WorktidePermission::VIEW
            && \in_array($role, [WorkspaceMemberRole::Member], true);
    }
}
