<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\AIRecommendation;
use App\Entity\Comment;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Enum\TagScope;
use App\Entity\Enum\TaskPriority;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\TagRepository;
use App\Repository\TrackerRepository;
use App\Service\Ai\RecommendationApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for applying an accepted triage recommendation to a Task:
 * resolvable tracker/priority/tags are set, the summary becomes an internal
 * comment, and unresolvable values are skipped.
 */
final class RecommendationApplierTest extends TestCase
{
    public function testAppliesTrackerPriorityTagsAndSummaryComment(): void
    {
        $ws = new Workspace();
        $task = (new Task())->setTitle('t')->setWorkspace($ws);
        $this->assignId($task, Uuid::v7());   // a persisted task always has an id
        $tracker = (new Tracker())->setName('Bug')->setWorkspace($ws);
        $tag = (new Tag())->setName('auth')->setScope(TagScope::Any)->setWorkspace($ws);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($task);

        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });

        $trackers = $this->createStub(TrackerRepository::class);
        $trackers->method('findOneBy')->willReturn($tracker);

        $tags = $this->createStub(TagRepository::class);
        $tags->method('findBy')->willReturn([$tag]);

        $applier = new RecommendationApplier($em, $trackers, $tags);

        $applier->apply($this->recommendation([
            'summary' => 'Login wirft 500.',
            'tracker' => 'Bug',
            'priority' => 'high',
            'tags' => ['auth'],
        ]), new User());

        self::assertSame($tracker, $task->getTracker());
        self::assertSame(TaskPriority::High, $task->getPriority());
        self::assertCount(1, $task->getTags());

        $comments = array_filter($persisted, static fn (object $o): bool => $o instanceof Comment);
        self::assertCount(1, $comments);
        self::assertStringContainsString('Login wirft 500.', reset($comments)->getContent());
    }

    public function testSkipsUnresolvableTrackerAndTags(): void
    {
        $ws = new Workspace();
        $task = (new Task())->setTitle('t')->setWorkspace($ws);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($task);
        $em->method('persist')->willReturnCallback(static function (): void {});

        $trackers = $this->createStub(TrackerRepository::class);
        $trackers->method('findOneBy')->willReturn(null);   // unknown tracker

        $tags = $this->createStub(TagRepository::class);
        $tags->method('findBy')->willReturn([]);            // unknown tag

        $applier = new RecommendationApplier($em, $trackers, $tags);

        $applier->apply($this->recommendation([
            'summary' => '',
            'tracker' => 'Epic',
            'priority' => 'nope',
            'tags' => ['ghost'],
        ]), new User());

        self::assertNull($task->getTracker());
        self::assertCount(0, $task->getTags());
    }

    private function assignId(object $entity, Uuid $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }

    /**
     * @param array<string, mixed> $suggestion
     */
    private function recommendation(array $suggestion): AIRecommendation
    {
        return (new AIRecommendation())
            ->setWorkspace(new Workspace())
            ->setTarget(RecommendationTarget::Task)
            ->setTargetId(Uuid::v7())
            ->setSuggestion($suggestion);
    }
}
