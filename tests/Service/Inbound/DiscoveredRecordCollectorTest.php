<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Channels\EntitySnapshot;
use App\Channels\ExternalParticipant;
use App\Entity\Channel;
use App\Entity\DiscoveredExternalRecord;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\DiscoveredExternalRecordRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Service\Inbound\DiscoveredRecordCollector;
use App\Service\Inbound\InboundImportFilter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Capture side of C.7.6: a relevant unmapped snapshot is parked as a
 * DiscoveredExternalRecord; an irrelevant one is dropped; a repeat upserts the
 * existing row. Uses the real InboundImportFilter over stubbed repos so the
 * relevance gate is exercised end-to-end.
 */
final class DiscoveredRecordCollectorTest extends TestCase
{
    public function testRelevantSnapshotIsCaptured(): void
    {
        $captured = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$captured): void {
            $captured[] = $e;
        });

        // Filter resolves the assignee → relevant.
        $collector = new DiscoveredRecordCollector(
            $em,
            $this->filterResolving(true),
            $this->records(null),
        );

        $collector->capture($this->channel(), $this->snapshot());

        self::assertCount(1, $captured);
        $record = $captured[0];
        self::assertInstanceOf(DiscoveredExternalRecord::class, $record);
        self::assertSame('42', $record->getExternalId());
        self::assertSame('Broken login', $record->getTitle());
        self::assertSame('task', $record->getEntityType());
        self::assertSame([['externalUserId' => 'u-1', 'email' => null, 'role' => 'assignee']], $record->getParticipants());
    }

    public function testIrrelevantSnapshotIsDropped(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $collector = new DiscoveredRecordCollector(
            $em,
            $this->filterResolving(false),
            $this->records(null),
        );

        $collector->capture($this->channel(), $this->snapshot());
    }

    public function testExistingRecordIsUpdatedNotDuplicated(): void
    {
        $existing = (new DiscoveredExternalRecord())
            ->setChannel($this->channel())
            ->setWorkspace(new Workspace())
            ->setEntityType('task')
            ->setExternalId('42')
            ->setTitle('Old title');

        $captured = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$captured): void {
            $captured[] = $e;
        });

        $collector = new DiscoveredRecordCollector(
            $em,
            $this->filterResolving(true),
            $this->records($existing),
        );

        $collector->capture($this->channel(), $this->snapshot());

        self::assertSame([$existing], $captured, 'reuses the existing row');
        self::assertSame('Broken login', $existing->getTitle(), 'preview refreshed');
    }

    private function filterResolving(bool $relevant): InboundImportFilter
    {
        $identities = $this->createStub(ExternalIdentityRepository::class);
        $identities->method('findUserByExternalUserId')->willReturn($relevant ? new User() : null);
        $members = $this->createStub(WorkspaceMemberRepository::class);
        $members->method('findByWorkspaceAndEmail')->willReturn(null);

        return new InboundImportFilter($identities, $members);
    }

    private function records(?DiscoveredExternalRecord $existing): DiscoveredExternalRecordRepository
    {
        $repo = $this->createStub(DiscoveredExternalRecordRepository::class);
        $repo->method('findOneByChannelExternal')->willReturn($existing);

        return $repo;
    }

    private function channel(): Channel
    {
        return (new Channel())->setWorkspace(new Workspace())->setAdapterCode('redmine');
    }

    private function snapshot(): EntitySnapshot
    {
        return new EntitySnapshot(
            entityType: 'task',
            externalId: '42',
            fields: ['title' => 'Broken login', 'description' => 'cannot log in'],
            participants: [new ExternalParticipant(externalUserId: 'u-1')],
        );
    }
}
