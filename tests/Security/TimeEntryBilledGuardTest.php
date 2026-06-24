<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Enum\Capability;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\EventSubscriber\TimeEntryBilledGuardListener;
use App\Repository\RolePermissionOverrideRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Security\DefaultPermissions;
use App\Security\PermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the self-service "billed" feature (#114):
 *  - the default-grant matrix exposes the new capability to the right roles
 *  - the preUpdate guard allows / denies an isBilled change correctly
 *
 * No database — the guard is pure decision logic over a Doctrine
 * change-set plus the injected Security + PermissionResolver, so we drive
 * it with a hand-built PreUpdateEventArgs and mocks.
 */
final class TimeEntryBilledGuardTest extends TestCase
{
    public function testNewCapabilityDefaults(): void
    {
        // Owner + Admin inherit everything (Admin minus billing-settings).
        self::assertTrue(DefaultPermissions::isGrantedByDefault(WorkspaceMemberRole::Owner, Capability::TimeEntryToggleBilledOwn));
        self::assertTrue(DefaultPermissions::isGrantedByDefault(WorkspaceMemberRole::Admin, Capability::TimeEntryToggleBilledOwn));
        // Members may self-bill by default…
        self::assertTrue(DefaultPermissions::isGrantedByDefault(WorkspaceMemberRole::Member, Capability::TimeEntryToggleBilledOwn));
        // …Guests may not.
        self::assertFalse(DefaultPermissions::isGrantedByDefault(WorkspaceMemberRole::Guest, Capability::TimeEntryToggleBilledOwn));
    }

    public function testOwnerWithoutCapabilityIsDenied(): void
    {
        $actor = $this->user();
        $entry = $this->entry($actor, locked: false);

        $this->expectException(AccessDeniedException::class);
        $this->guard($actor, can: false)->preUpdate($this->billedChange($entry));
    }

    public function testOwnerWithCapabilityIsAllowed(): void
    {
        $actor = $this->user();
        $entry = $this->entry($actor, locked: false);

        $this->guard($actor, can: true)->preUpdate($this->billedChange($entry));
        $this->expectNotToPerformAssertions();
    }

    public function testLockedEntryIsAlwaysDenied(): void
    {
        $actor = $this->user();
        $entry = $this->entry($actor, locked: true);

        // Even with the capability, a finalised (locked) entry is frozen.
        $this->expectException(AccessDeniedException::class);
        $this->guard($actor, can: true)->preUpdate($this->billedChange($entry));
    }

    public function testNonOwnerRidesAlongOnUpdateOthers(): void
    {
        $owner = $this->user();
        $admin = $this->user();
        $entry = $this->entry($owner, locked: false);

        // Actor is NOT the owner → they already passed update_others to
        // reach this PATCH; the guard must not re-block. can() returning
        // false here proves the guard never consults it for non-owners.
        $this->guard($admin, can: false)->preUpdate($this->billedChange($entry));
        $this->expectNotToPerformAssertions();
    }

    public function testUnauthenticatedSystemWriteIsAllowed(): void
    {
        $entry = $this->entry($this->user(), locked: false);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        // Resolver that explodes if consulted — proves the guard returns
        // early for system writes without ever evaluating permissions.
        (new TimeEntryBilledGuardListener($security, $this->explodingResolver()))
            ->preUpdate($this->billedChange($entry));
        $this->expectNotToPerformAssertions();
    }

    public function testUnrelatedFieldChangeIsIgnored(): void
    {
        $actor = $this->user();
        $entry = $this->entry($actor, locked: false);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor);

        $changeSet = ['note' => ['alt', 'neu']];
        $args = new PreUpdateEventArgs($entry, $this->createStub(EntityManagerInterface::class), $changeSet);

        // A note edit must not touch the permission machinery at all.
        (new TimeEntryBilledGuardListener($security, $this->explodingResolver()))->preUpdate($args);
        $this->expectNotToPerformAssertions();
    }

    private function guard(User $actor, bool $can): TimeEntryBilledGuardListener
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor);

        // PermissionResolver is final → can't be doubled. Drive a REAL
        // resolver via mocked repositories: Owner role short-circuits to
        // granted; Guest (with no override) lacks the new capability by
        // default — exactly the can=true / can=false we need.
        $member = (new WorkspaceMember())
            ->setRole($can ? WorkspaceMemberRole::Owner : WorkspaceMemberRole::Guest);
        $wsMembers = $this->createStub(WorkspaceMemberRepository::class);
        $wsMembers->method('findOneBy')->willReturn($member);
        $overrides = $this->createStub(RolePermissionOverrideRepository::class);
        $overrides->method('findOverride')->willReturn(null);

        return new TimeEntryBilledGuardListener($security, new PermissionResolver($wsMembers, $overrides));
    }

    private function explodingResolver(): PermissionResolver
    {
        $wsMembers = $this->createStub(WorkspaceMemberRepository::class);
        $wsMembers->method('findOneBy')->willThrowException(
            new \LogicException('PermissionResolver must not be consulted here'),
        );

        return new PermissionResolver($wsMembers, $this->createStub(RolePermissionOverrideRepository::class));
    }

    private function billedChange(TimeEntry $entry): PreUpdateEventArgs
    {
        $changeSet = ['isBilled' => [false, true]];

        return new PreUpdateEventArgs($entry, $this->createStub(EntityManagerInterface::class), $changeSet);
    }

    private function user(): User
    {
        $u = new User();
        $this->setId($u, Uuid::v7());

        return $u;
    }

    private function entry(User $owner, bool $locked): TimeEntry
    {
        $entry = (new TimeEntry())
            ->setUser($owner)
            ->setIsLocked($locked);
        $entry->setWorkspace(new Workspace());

        return $entry;
    }

    private function setId(object $entity, Uuid $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
