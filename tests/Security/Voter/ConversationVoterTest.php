<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\WorkspaceMemberRepository;
use App\Security\Voter\ConversationVoter;
use App\Security\Voter\WorktidePermission;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Mailbox visibility at the item level: personal mailboxes are owner-only
 * (plus admins), shared mailboxes are internal-members-only (guests excluded).
 */
final class ConversationVoterTest extends TestCase
{
    public function testPersonalMailboxOwnerGranted(): void
    {
        $user = new User();
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->decide($user, $this->conversation(shared: false, owner: $user), null),
        );
    }

    public function testPersonalMailboxOtherMemberDenied(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->decide(new User(), $this->conversation(shared: false, owner: new User()), WorkspaceMemberRole::Member),
        );
    }

    public function testPersonalMailboxAdminGranted(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->decide(new User(), $this->conversation(shared: false, owner: new User()), WorkspaceMemberRole::Admin),
        );
    }

    public function testSharedMailboxMemberGranted(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->decide(new User(), $this->conversation(shared: true, owner: null), WorkspaceMemberRole::Member),
        );
    }

    public function testSharedMailboxGuestDenied(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->decide(new User(), $this->conversation(shared: true, owner: null), WorkspaceMemberRole::Guest),
        );
    }

    public function testNonMemberDenied(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->decide(new User(), $this->conversation(shared: true, owner: null), null),
        );
    }

    // --- helpers ----------------------------------------------------

    private function conversation(bool $shared, ?User $owner): Conversation
    {
        $channel = (new Channel())->setWorkspace(new Workspace())->setIsShared($shared)->setOwnerUser($owner);

        return (new Conversation())->setChannel($channel);
    }

    /**
     * Vote as $user on $conv, where the member lookup returns a member with the
     * given role (or null = the user is not a member of the workspace).
     */
    private function decide(User $user, Conversation $conv, ?WorkspaceMemberRole $role): int
    {
        $repo = $this->createStub(WorkspaceMemberRepository::class);
        $repo->method('findOneBy')->willReturn(
            $role === null ? null : (new WorkspaceMember())->setRole($role),
        );

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return (new ConversationVoter($repo))->vote($token, $conv, [WorktidePermission::EDIT]);
    }
}
