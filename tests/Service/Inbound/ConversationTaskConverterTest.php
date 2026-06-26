<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\Conversation;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\Workspace;
use App\Repository\InboundEventRepository;
use App\Repository\TaskStatusRepository;
use App\Service\Inbound\ConversationTaskConverter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ConversationTaskConverterTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testConvertBuildsTaskFromConversation(): void
    {
        $workspace = new Workspace();
        $conversation = $this->createStub(Conversation::class);
        $conversation->method('getWorkspace')->willReturn($workspace);
        $conversation->method('getSubject')->willReturn('Cannot log in');

        $project = $this->createStub(Project::class);
        $project->method('getWorkspace')->willReturn($workspace);
        $project->method('getKey')->willReturn('CNV');

        $task = $this->converter($workspace)->convert($conversation, $project, null);

        self::assertSame('Cannot log in', $task->getTitle());
        self::assertSame($project, $task->getProject());
        self::assertSame($conversation, $task->getSourceConversation());
        self::assertSame(TaskCreatedVia::Email, $task->getCreatedVia());
        self::assertStringStartsWith('CNV-', $task->getIdentifier());
        self::assertInstanceOf(Task::class, $this->persisted[0] ?? null);
    }

    public function testExplicitTitleOverridesSubject(): void
    {
        $workspace = new Workspace();
        $conversation = $this->createStub(Conversation::class);
        $conversation->method('getWorkspace')->willReturn($workspace);
        $conversation->method('getSubject')->willReturn('Subject');
        $project = $this->createStub(Project::class);
        $project->method('getWorkspace')->willReturn($workspace);
        $project->method('getKey')->willReturn('CNV');

        $task = $this->converter($workspace)->convert($conversation, $project, 'Custom title');

        self::assertSame('Custom title', $task->getTitle());
    }

    public function testCrossWorkspaceIsRejected(): void
    {
        $conversation = $this->createStub(Conversation::class);
        $conversation->method('getWorkspace')->willReturn(new Workspace());
        $project = $this->createStub(Project::class);
        $project->method('getWorkspace')->willReturn(new Workspace()); // different

        $this->expectException(\DomainException::class);
        $this->converter(new Workspace())->convert($conversation, $project, null);
    }

    private function converter(Workspace $workspace): ConversationTaskConverter
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

        $inbound = $this->createStub(InboundEventRepository::class);
        $inbound->method('findBy')->willReturn([]);

        return new ConversationTaskConverter($em, $statuses, $inbound);
    }
}
