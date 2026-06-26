<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\DiscoveredExternalRecord;
use App\Entity\Enum\DiscoveredRecordState;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\EntitySync;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\Workspace;
use App\Repository\TaskStatusRepository;
use App\Service\Inbound\DiscoveredRecordImporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Resolution side of C.7.6: importing a discovered record creates a Task + the
 * missing EntitySync mapping and settles the record; acting on a non-Pending
 * record is rejected so a double action can't create two tasks.
 */
final class DiscoveredRecordImporterTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testImportCreatesTaskAndMappingAndSettlesRecord(): void
    {
        $workspace = new Workspace();
        $record = $this->record($workspace, DiscoveredRecordState::Pending);
        $project = (new Project())->setKey('imp')->setWorkspace($workspace);

        $task = $this->importer($workspace)->import($record, $project);

        self::assertSame('Broken login', $task->getTitle());
        self::assertSame($project, $task->getProject());
        self::assertSame(TaskCreatedVia::Api, $task->getCreatedVia());
        self::assertStringStartsWith('IMP-', $task->getIdentifier());

        self::assertSame(DiscoveredRecordState::Imported, $record->getState());
        self::assertSame($task->getId(), $record->getImportedEntityId());

        $mapping = $this->firstOf(EntitySync::class);
        self::assertInstanceOf(EntitySync::class, $mapping);
        self::assertSame('42', $mapping->getExternalId());
        self::assertSame($task->getId(), $mapping->getEntityId());
        self::assertSame('task', $mapping->getEntityType());
    }

    public function testImportOnNonPendingRecordIsRejected(): void
    {
        $workspace = new Workspace();
        $record = $this->record($workspace, DiscoveredRecordState::Imported);
        $project = (new Project())->setKey('imp')->setWorkspace($workspace);

        $this->expectException(\DomainException::class);
        $this->importer($workspace)->import($record, $project);
    }

    public function testImportIntoForeignWorkspaceIsRejected(): void
    {
        $record = $this->record(new Workspace(), DiscoveredRecordState::Pending);
        $project = (new Project())->setKey('imp')->setWorkspace(new Workspace()); // different ws

        $this->expectException(\DomainException::class);
        $this->importer(new Workspace())->import($record, $project);
    }

    private function importer(Workspace $workspace): DiscoveredRecordImporter
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
            if ($e instanceof Task && $e->getId() === null) {
                (new \ReflectionProperty($e, 'id'))->setValue($e, Uuid::v7());
            }
            $this->persisted[] = $e;
        });

        $statuses = $this->createStub(TaskStatusRepository::class);
        $statuses->method('findOneBy')->willReturn(new TaskStatus());

        return new DiscoveredRecordImporter($em, $statuses, new SyncReentryGuard());
    }

    private function record(Workspace $workspace, DiscoveredRecordState $state): DiscoveredExternalRecord
    {
        return (new DiscoveredExternalRecord())
            ->setChannel((new Channel())->setWorkspace($workspace)->setAdapterCode('redmine'))
            ->setWorkspace($workspace)
            ->setEntityType('task')
            ->setExternalId('42')
            ->setTitle('Broken login')
            ->setFields(['title' => 'Broken login', 'description' => 'cannot log in'])
            ->setState($state);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function firstOf(string $class): ?object
    {
        foreach ($this->persisted as $e) {
            if ($e instanceof $class) {
                return $e;
            }
        }

        return null;
    }
}
