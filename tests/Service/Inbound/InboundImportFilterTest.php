<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Channels\ExternalParticipant;
use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\ExternalIdentityRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Service\Inbound\InboundImportFilter;
use PHPUnit\Framework\TestCase;

/**
 * Pins the relevance rule: an external ticket counts only when a participant
 * (assignee OR watcher) resolves to a workspace member — explicit mapping first,
 * email match as fallback.
 */
final class InboundImportFilterTest extends TestCase
{
    public function testExplicitMappingMakesTicketRelevant(): void
    {
        $user = new User();
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findUserByExternalUserId')->willReturn($user);

        $members = $this->createMock(WorkspaceMemberRepository::class);
        // Explicit mapping wins → email fallback must not be consulted.
        $members->expects(self::never())->method('findByWorkspaceAndEmail');

        $filter = new InboundImportFilter($identities, $members);

        self::assertTrue($filter->isRelevant($this->channel(), [
            new ExternalParticipant(externalUserId: 'acc-123'),
        ]));
    }

    public function testEmailMatchMakesTicketRelevant(): void
    {
        $user = new User();
        $member = $this->createStub(WorkspaceMember::class);
        $member->method('getUser')->willReturn($user);

        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findUserByExternalUserId')->willReturn(null);

        $members = $this->createStub(WorkspaceMemberRepository::class);
        $members->method('findByWorkspaceAndEmail')->willReturn($member);

        $filter = new InboundImportFilter($identities, $members);

        self::assertTrue($filter->isRelevant($this->channel(), [
            new ExternalParticipant(email: 'sven@worktide.test'),
        ]));
    }

    public function testWatcherMatchAlsoCounts(): void
    {
        $member = $this->createStub(WorkspaceMember::class);
        $member->method('getUser')->willReturn(new User());

        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findUserByExternalUserId')->willReturn(null);
        $members = $this->createStub(WorkspaceMemberRepository::class);
        $members->method('findByWorkspaceAndEmail')->willReturn($member);

        $filter = new InboundImportFilter($identities, $members);

        // No assignee, only a watcher — still relevant.
        self::assertTrue($filter->isRelevant($this->channel(), [
            new ExternalParticipant(email: 'mira@worktide.test', role: ExternalParticipant::ROLE_WATCHER),
        ]));
    }

    public function testNoMatchIsNotRelevant(): void
    {
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findUserByExternalUserId')->willReturn(null);
        $members = $this->createStub(WorkspaceMemberRepository::class);
        $members->method('findByWorkspaceAndEmail')->willReturn(null);

        $filter = new InboundImportFilter($identities, $members);

        self::assertFalse($filter->isRelevant($this->channel(), [
            new ExternalParticipant(externalUserId: 'acc-999', email: 'stranger@example.com'),
            new ExternalParticipant(email: 'someone-else@example.com', role: ExternalParticipant::ROLE_WATCHER),
        ]));
    }

    private function channel(): Channel
    {
        return (new Channel())
            ->setWorkspace(new Workspace())
            ->setAdapterCode('jira');
    }
}
