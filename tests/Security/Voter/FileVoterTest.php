<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Enum\FileTarget;
use App\Entity\File;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\CommentRepository;
use App\Repository\DocumentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Repository\WorkspaceRepository;
use App\Security\Voter\FileVoter;
use App\Security\Voter\WorktidePermission;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A file attached to a User (FileTarget::User) is accessible only to that user
 * themselves, or to a workspace admin (WorkspaceVoter EDIT). A regular
 * workspace member must NOT be able to read or delete another user's file —
 * the bug this covers is the previous "any member → all permissions" grant.
 */
final class FileVoterTest extends TestCase
{
    public function testOwnerOfTheUserFileHasAccess(): void
    {
        $me = $this->userWithId();
        // File targets me; I am the caller → self, always granted.
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($me, target: $me, adminOnWorkspace: false, attribute: WorktidePermission::DELETE),
        );
    }

    public function testOtherMemberCannotReadOrDeleteAnotherUsersFile(): void
    {
        foreach ([WorktidePermission::VIEW, WorktidePermission::EDIT, WorktidePermission::DELETE] as $perm) {
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                $this->vote($this->userWithId(), target: $this->userWithId(), adminOnWorkspace: false, attribute: $perm),
                "a non-admin member must be denied $perm on another user's file",
            );
        }
    }

    public function testWorkspaceAdminCanActOnAnotherUsersFile(): void
    {
        foreach ([WorktidePermission::VIEW, WorktidePermission::EDIT, WorktidePermission::DELETE] as $perm) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $this->vote($this->userWithId(), target: $this->userWithId(), adminOnWorkspace: true, attribute: $perm),
                "a workspace admin should be granted $perm on another user's file",
            );
        }
    }

    private function userWithId(): User
    {
        $u = new User();
        $prop = new \ReflectionProperty(User::class, 'id');
        $prop->setValue($u, Uuid::v7());

        return $u;
    }

    /**
     * Vote as $caller on a User-target file that belongs to $target. The
     * AccessDecisionManager (WorkspaceVoter EDIT on the workspace) returns
     * $adminOnWorkspace.
     */
    private function vote(User $caller, User $target, bool $adminOnWorkspace, string $attribute): int
    {
        $file = (new File())
            ->setTarget(FileTarget::User)
            ->setTargetId(Uuid::v7());
        $file->setWorkspace(new Workspace());

        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturn($adminOnWorkspace);

        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($target);

        $voter = new FileVoter(
            $decisions,
            $this->createStub(ProjectRepository::class),
            $this->createStub(TaskRepository::class),
            $this->createStub(WorkspaceRepository::class),
            $users,
            $this->createStub(CommentRepository::class),
            $this->createStub(DocumentRepository::class),
            $this->createStub(WorkspaceMemberRepository::class),
        );

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($caller);

        return $voter->vote($token, $file, [$attribute]);
    }
}
