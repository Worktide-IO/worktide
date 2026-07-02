<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Task;
use App\Entity\Workspace;
use App\EventSubscriber\SearchIndexingListener;
use App\Message\SyncSearchIndexMessage;
use App\Service\Search\SearchDocumentFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class SearchIndexingListenerTest extends TestCase
{
    public function testDispatchesForSearchableTypeWhenMeilisearch(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $dispatched = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $m, array $stamps = []) use (&$dispatched): Envelope {
            $dispatched[] = $m;

            return new Envelope($m);
        });

        $listener = new SearchIndexingListener(new SearchDocumentFactory(), $bus, 'meilisearch');
        $task = $this->task();

        $listener->postPersist(new PostPersistEventArgs($task, $em));
        $listener->postFlush(new PostFlushEventArgs($em));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(SyncSearchIndexMessage::class, $dispatched[0]);
        self::assertSame('task', $dispatched[0]->getType());
        self::assertFalse($dispatched[0]->isDelete());
    }

    public function testDoesNotDispatchWhenMysql(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $listener = new SearchIndexingListener(new SearchDocumentFactory(), $bus, 'mysql');
        $listener->postPersist(new PostPersistEventArgs($this->task(), $em));
        $listener->postFlush(new PostFlushEventArgs($em));
    }

    public function testIgnoresNonSearchableEntity(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $listener = new SearchIndexingListener(new SearchDocumentFactory(), $bus, 'meilisearch');
        $listener->postPersist(new PostPersistEventArgs(new Workspace(), $em));
        $listener->postFlush(new PostFlushEventArgs($em));
    }

    private function task(): Task
    {
        $ws = new Workspace();
        (new \ReflectionProperty($ws, 'id'))->setValue($ws, Uuid::v7());
        $task = (new Task())->setTitle('X')->setWorkspace($ws);
        (new \ReflectionProperty($task, 'id'))->setValue($task, Uuid::v7());

        return $task;
    }
}
