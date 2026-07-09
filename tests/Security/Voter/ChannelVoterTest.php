<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Channel;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\WorkspaceMemberRepository;
use App\Security\Voter\ChannelVoter;
use App\Security\Voter\WorktidePermission;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Channel management rules:
 *  - personal mailbox: owner + workspace admins manage it, others get nothing;
 *  - shared mailbox: members may VIEW, only owners/admins may EDIT/DELETE/MANAGE.
 */
final class ChannelVoterTest extends TestCase
{
    public function testPersonalMailboxOwnerHasAllPermissions(): void
    {
        $user = new User();
        $channel = $this->channel(shared: false, owner: $user);
        foreach (WorktidePermission::ALL as $perm) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->decide($user, $channel, null, $perm),
                "owner should be granted $perm",
            );
        }
    }

    public function testPersonalMailboxOtherMemberDenied(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->decide(new User(), $this->channel(shared: false, owner: new User()), WorkspaceMemberRole::Member, WorktidePermission::VIEW),
        );
    }

    public function testPersonalMailboxAdminGranted(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->decide(new User(), $this->channel(shared: false, owner: new User()), WorkspaceMemberRole::Admin, WorktidePermission::EDIT),
        );
    }

    public function testSharedMailboxMemberCanViewButNotEdit(): void
    {
        $channel = $this->channel(shared: true, owner: null);
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->decide(new User(), $channel, WorkspaceMemberRole::Member, WorktidePermission::VIEW),
        );
        foreach ([WorktidePermission::EDIT, WorktidePermission::DELETE, WorktidePermission::MANAGE] as $perm) {
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                $this->decide(new User(), $channel, WorkspaceMemberRole::Member, $perm),
                "member must not be able to $perm a shared channel",
            );
        }
    }

    public function testSharedMailboxAdminCanEdit(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->decide(new User(), $this->channel(shared: true, owner: null), WorkspaceMemberRole::Admin, WorktidePermission::EDIT),
        );
    }

    public function testSharedMailboxGuestDenied(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->decide(new User(), $this->channel(shared: true, owner: null), WorkspaceMemberRole::Guest, WorktidePermission::VIEW),
        );
    }

    public function testNonMemberDenied(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->decide(new User(), $this->channel(shared: true, owner: null), null, WorktidePermission::VIEW),
        );
    }

    // --- helpers ----------------------------------------------------

    private function channel(bool $shared, ?User $owner): Channel
    {
        return (new Channel())->setWorkspace(new Workspace())->setIsShared($shared)->setOwnerUser($owner);
    }

    /**
     * Vote as $user on $channel for $attribute, where the member lookup returns
     * a member with the given role (or null = not a member of the workspace).
     */
    private function decide(User $user, Channel $channel, ?WorkspaceMemberRole $role, string $attribute): int
    {
        $repo = $this->createStub(WorkspaceMemberRepository::class);
        $repo->method('findOneBy')->willReturn(
            $role === null ? null : (new WorkspaceMember())->setRole($role),
        );

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return (new ChannelVoter($repo))->vote($token, $channel, [$attribute]);
    }
}
