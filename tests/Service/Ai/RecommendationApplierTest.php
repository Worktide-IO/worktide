<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\AIRecommendation;
use App\Entity\Comment;
use App\Entity\Conversation;
use App\Entity\Enum\RecommendationKind;
use App\Entity\Enum\RecommendationTarget;
use App\Entity\Enum\TagScope;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Customer;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\Enum\SocialPostStatus;
use App\Entity\OutboundMessage;
use App\Entity\Product;
use App\Entity\SocialPost;
use App\Entity\Workspace;
use App\Channels\AdapterRegistry;
use App\Repository\ChannelRepository;
use App\Repository\TagRepository;
use App\Repository\TrackerRepository;
use App\Service\Ai\RecommendationApplier;
use App\Service\Inbound\ConversationTaskConverter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Applying accepted recommendations: triage → mutate the Task; ticket-from-
 * conversation → create a Task via the converter (or demand a project).
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

        $applier = new RecommendationApplier($em, $trackers, $tags, $this->createStub(ConversationTaskConverter::class), $this->createStub(ChannelRepository::class), new AdapterRegistry([], [], []));

        $applier->apply($this->taskRecommendation([
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
        $trackers->method('findOneBy')->willReturn(null);
        $tags = $this->createStub(TagRepository::class);
        $tags->method('findBy')->willReturn([]);

        $applier = new RecommendationApplier($em, $trackers, $tags, $this->createStub(ConversationTaskConverter::class), $this->createStub(ChannelRepository::class), new AdapterRegistry([], [], []));

        $applier->apply($this->taskRecommendation([
            'summary' => '',
            'tracker' => 'Epic',
            'priority' => 'nope',
            'tags' => ['ghost'],
        ]), new User());

        self::assertNull($task->getTracker());
        self::assertCount(0, $task->getTags());
    }

    public function testTicketFromConversationCreatesTaskViaConverter(): void
    {
        $ws = new Workspace();
        $this->assignId($ws, Uuid::v7());
        $conversation = (new Conversation())->setWorkspace($ws)->setChannel(new \App\Entity\Channel());
        $project = (new Project())->setWorkspace($ws);
        $projectId = Uuid::v7();
        $this->assignId($project, $projectId);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class): object => $class === Project::class ? $project : $conversation,
        );

        $converter = $this->createMock(ConversationTaskConverter::class);
        $converter->expects(self::once())
            ->method('convert')
            ->with($conversation, $project, 'Fix login')
            ->willReturn(new Task());

        $applier = new RecommendationApplier(
            $em,
            $this->createStub(TrackerRepository::class),
            $this->createStub(TagRepository::class),
            $converter,
            $this->createStub(ChannelRepository::class),
            new AdapterRegistry([], [], []),
        );

        $applier->apply($this->ticketRecommendation([
            'title' => 'Fix login',
            'summary' => '…',
            'suggestedProject' => $projectId->toRfc4122(),
        ]), new User());
    }

    public function testTicketFromConversationWithoutProjectThrows(): void
    {
        $conversation = (new Conversation())->setWorkspace(new Workspace())->setChannel(new \App\Entity\Channel());

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class): ?object => $class === Project::class ? null : $conversation,
        );

        $converter = $this->createMock(ConversationTaskConverter::class);
        $converter->expects(self::never())->method('convert');

        $applier = new RecommendationApplier(
            $em,
            $this->createStub(TrackerRepository::class),
            $this->createStub(TagRepository::class),
            $converter,
            $this->createStub(ChannelRepository::class),
            new AdapterRegistry([], [], []),
        );

        $this->expectException(\DomainException::class);
        $applier->apply($this->ticketRecommendation([
            'title' => 'Fix login',
            'summary' => '…',
            'suggestedProject' => null,
        ]), new User());
    }

    public function testMarketingSocialDraftCreatesDraftPostWithMatchingTargets(): void
    {
        $product = (new Product())->setName('Widget')->setSlug('widget')->setWorkspace(new Workspace());

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($product);
        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });

        $channel = (new \App\Entity\Channel())->setName('LinkedIn')->setAdapterCode('social_test');
        $channels = $this->createStub(ChannelRepository::class);
        $channels->method('findEnabledSocial')->willReturn([$channel]);

        $applier = new RecommendationApplier(
            $em,
            $this->createStub(TrackerRepository::class),
            $this->createStub(TagRepository::class),
            $this->createStub(ConversationTaskConverter::class),
            $channels,
            new AdapterRegistry([], [], []),
        );

        $applier->apply($this->productRecommendation([
            'summary' => 'Meet Widget.',
            'variants' => [
                ['adapterCode' => 'social_test', 'body' => 'Hello from Widget on LinkedIn'],
                ['adapterCode' => 'social_absent', 'body' => 'no connected channel'],
            ],
        ]), new User());

        $posts = array_values(array_filter($persisted, static fn (object $o): bool => $o instanceof SocialPost));
        self::assertCount(1, $posts);
        $post = $posts[0];
        self::assertSame(SocialPostStatus::Draft, $post->getStatus());
        self::assertSame('Meet Widget.', $post->getBody());
        self::assertCount(1, $post->getTargets());
        $target = $post->getTargets()->first();
        self::assertNotFalse($target);
        self::assertSame($channel, $target->getChannel());
        self::assertSame('Hello from Widget on LinkedIn', $target->getBodyOverride());
    }

    public function testCustomerUpgradeOutreachCreatesQueuedOutboundMessage(): void
    {
        $customer = (new Customer())->setName('Acme GmbH')->setEmail('kunde@example.com')->setWorkspace(new Workspace());

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($customer);
        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });

        $channel = (new \App\Entity\Channel())->setName('Mail')->setAdapterCode('email_imap');
        $channels = $this->createStub(ChannelRepository::class);
        $channels->method('findEnabledEmailOutbound')->willReturn([$channel]);

        $applier = new RecommendationApplier(
            $em,
            $this->createStub(TrackerRepository::class),
            $this->createStub(TagRepository::class),
            $this->createStub(ConversationTaskConverter::class),
            $channels,
            new AdapterRegistry([], [], []),
        );

        $reviewer = new User();
        $applier->apply($this->customerRecommendation([
            'subject' => 'Zeit für ein Update',
            'body' => 'Hallo Acme, es gibt eine neue Version …',
        ]), $reviewer);

        $msgs = array_values(array_filter($persisted, static fn (object $o): bool => $o instanceof OutboundMessage));
        self::assertCount(1, $msgs);
        $msg = $msgs[0];
        self::assertSame('kunde@example.com', $msg->getRecipientRaw());
        self::assertSame('Zeit für ein Update', $msg->getSubject());
        self::assertSame('Hallo Acme, es gibt eine neue Version …', $msg->getBody());
        self::assertSame(OutboundMessageStatus::Queued, $msg->getStatus());
        self::assertSame($reviewer, $msg->getCreatedByUser());
    }

    private function assignId(object $entity, Uuid $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }

    /** @param array<string, mixed> $suggestion */
    private function taskRecommendation(array $suggestion): AIRecommendation
    {
        return (new AIRecommendation())
            ->setWorkspace(new Workspace())
            ->setTarget(RecommendationTarget::Task)
            ->setTargetId(Uuid::v7())
            ->setSuggestion($suggestion);
    }

    /** @param array<string, mixed> $suggestion */
    private function ticketRecommendation(array $suggestion): AIRecommendation
    {
        return (new AIRecommendation())
            ->setWorkspace(new Workspace())
            ->setTarget(RecommendationTarget::Conversation)
            ->setKind(RecommendationKind::TicketFromConversation)
            ->setTargetId(Uuid::v7())
            ->setSuggestion($suggestion);
    }

    /** @param array<string, mixed> $suggestion */
    private function productRecommendation(array $suggestion): AIRecommendation
    {
        return (new AIRecommendation())
            ->setWorkspace(new Workspace())
            ->setTarget(RecommendationTarget::Product)
            ->setKind(RecommendationKind::MarketingSocialDraft)
            ->setTargetId(Uuid::v7())
            ->setSuggestion($suggestion);
    }

    /** @param array<string, mixed> $suggestion */
    private function customerRecommendation(array $suggestion): AIRecommendation
    {
        return (new AIRecommendation())
            ->setWorkspace(new Workspace())
            ->setTarget(RecommendationTarget::Customer)
            ->setKind(RecommendationKind::CustomerUpgradeOutreach)
            ->setTargetId(Uuid::v7())
            ->setSuggestion($suggestion);
    }
}
