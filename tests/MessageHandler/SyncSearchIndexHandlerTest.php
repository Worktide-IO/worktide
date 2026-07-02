<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Task;
use App\Entity\Workspace;
use App\Message\SyncSearchIndexMessage;
use App\MessageHandler\SyncSearchIndexHandler;
use App\Service\Search\SearchDocument;
use App\Service\Search\SearchDocumentFactory;
use App\Service\Search\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SyncSearchIndexHandlerTest extends TestCase
{
    public function testDeleteMessageRemovesFromIndex(): void
    {
        $id = Uuid::v7();
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects(self::once())->method('delete')->with('task', $id);
        $provider->expects(self::never())->method('index');

        $em = $this->createStub(EntityManagerInterface::class);
        $this->handler($em, $provider)(new SyncSearchIndexMessage('task', $id, true));
    }

    public function testMissingEntityIsRemovedFromIndex(): void
    {
        $id = Uuid::v7();
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects(self::once())->method('delete')->with('task', $id);
        $provider->expects(self::never())->method('index');

        $this->handler($em, $provider)(new SyncSearchIndexMessage('task', $id, false));
    }

    public function testExistingEntityIsIndexed(): void
    {
        $ws = new Workspace();
        $this->assignId($ws, Uuid::v7());
        $task = (new Task())->setTitle('X')->setIdentifier('WT-1')->setWorkspace($ws);
        $task->onPrePersistTimestamps();
        $id = Uuid::v7();
        $this->assignId($task, $id);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($task);

        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects(self::once())->method('index')
            ->with(self::isInstanceOf(SearchDocument::class));
        $provider->expects(self::never())->method('delete');

        $this->handler($em, $provider)(new SyncSearchIndexMessage('task', $id, false));
    }

    public function testUnknownTypeIsIgnored(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects(self::never())->method('index');
        $provider->expects(self::never())->method('delete');

        $this->handler($em, $provider)(new SyncSearchIndexMessage('bogus', Uuid::v7(), false));
    }

    private function handler(EntityManagerInterface $em, SearchProviderInterface $provider): SyncSearchIndexHandler
    {
        return new SyncSearchIndexHandler($em, $provider, new SearchDocumentFactory());
    }

    private function assignId(object $entity, Uuid $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
