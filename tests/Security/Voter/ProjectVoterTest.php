<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectShareRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Security\Voter\ProjectVoter;
use App\Security\Voter\WorktidePermission;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Workspace-role → project-permission mapping. Focus: a Guest (read-mostly) may
 * only VIEW a project — never EDIT (which cascades to task create/edit) —
 * while regular Members edit and Owners/Admins manage.
 */
final class ProjectVoterTest extends TestCase
{
    public function testGuestCanOnlyView(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(WorkspaceMemberRole::Guest, WorktidePermission::VIEW));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(WorkspaceMemberRole::Guest, WorktidePermission::EDIT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(WorkspaceMemberRole::Guest, WorktidePermission::MANAGE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(WorkspaceMemberRole::Guest, WorktidePermission::DELETE));
    }

    public function testMemberCanEditButNotManage(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(WorkspaceMemberRole::Member, WorktidePermission::VIEW));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(WorkspaceMemberRole::Member, WorktidePermission::EDIT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(WorkspaceMemberRole::Member, WorktidePermission::MANAGE));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(WorkspaceMemberRole::Member, WorktidePermission::DELETE));
    }

    public function testAdminCanManage(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(WorkspaceMemberRole::Admin, WorktidePermission::EDIT));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(WorkspaceMemberRole::Admin, WorktidePermission::MANAGE));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(WorkspaceMemberRole::Admin, WorktidePermission::DELETE));
    }

    public function testOwnerCanManage(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(WorkspaceMemberRole::Owner, WorktidePermission::MANAGE));
    }

    public function testNonMemberWithoutProjectRoleDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(null, WorktidePermission::VIEW));
    }

    /** Vote as a workspace member with $wsRole (or null = not a member) and no project role. */
    private function vote(?WorkspaceMemberRole $wsRole, string $attribute): int
    {
        $wsMembers = $this->createStub(WorkspaceMemberRepository::class);
        $wsMembers->method('findOneBy')->willReturn(
            $wsRole === null ? null : (new WorkspaceMember())->setRole($wsRole),
        );
        $projectMembers = $this->createStub(ProjectMemberRepository::class);
        $projectMembers->method('findOneBy')->willReturn(null);
        $projectShares = $this->createStub(ProjectShareRepository::class);
        $projectShares->method('findRoleForUser')->willReturn(null);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new User());

        $project = (new Project())->setWorkspace(new Workspace());

        return (new ProjectVoter($wsMembers, $projectMembers, $projectShares))->vote($token, $project, [$attribute]);
    }
}
