<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Conversation;
use App\Entity\Enum\TagScope;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\Tracker;
use App\Entity\Workspace;
use App\Repository\CommentRepository;
use App\Repository\TagRepository;
use App\Repository\TrackerRepository;
use App\Service\Ai\TicketTriageAssistant;
use App\Service\Llm\AiUsageContext;
use App\Service\Llm\LlmProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for triage validation with a stubbed LLM (no real API call):
 * the assistant must only ever emit workspace-real values — unknown trackers,
 * bad priorities and invented tags are dropped so a suggestion always applies.
 */
final class TicketTriageAssistantTest extends TestCase
{
    public function testValidatesAgainstWorkspaceOptions(): void
    {
        $assistant = $this->assistant(
            trackers: ['Bug', 'Feature'],
            tags: ['auth', 'billing'],
            llmJson: [
                'summary' => '  Kunde meldet 500 beim Login.  ',
                'tracker' => 'bug',              // lowercase → canonical "Bug"
                'priority' => 'high',
                'tags' => ['auth', 'made-up'],   // one real, one invented
                'reasoning' => 'Login error is a defect.',
            ],
        );

        $out = $assistant->triageTask($this->task());
        $s = $out['suggestion'];

        self::assertSame('Kunde meldet 500 beim Login.', $s['summary']);
        self::assertSame('Bug', $s['tracker']);
        self::assertSame('high', $s['priority']);
        self::assertSame(['auth'], $s['tags']);
        self::assertSame(['made-up'], $s['suggestedNewTags']);
        self::assertSame('Login error is a defect.', $out['reasoning']);
    }

    public function testDropsUnknownTrackerAndBadPriority(): void
    {
        $assistant = $this->assistant(
            trackers: ['Bug'],
            tags: [],
            llmJson: [
                'summary' => 'x',
                'tracker' => 'Epic',        // not configured
                'priority' => 'critical',   // not a TaskPriority
                'tags' => 'not-an-array',
            ],
        );

        $s = $assistant->triageTask($this->task())['suggestion'];

        self::assertNull($s['tracker']);
        self::assertNull($s['priority']);
        self::assertSame([], $s['tags']);
    }

    public function testAvailabilityReflectsProvider(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(false);

        $assistant = new TicketTriageAssistant(
            $llm,
            $this->createStub(TrackerRepository::class),
            $this->createStub(TagRepository::class),
            $this->createStub(CommentRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new AiUsageContext(),
        );

        self::assertFalse($assistant->isAvailable());
    }

    // --- helpers ----------------------------------------------------

    /**
     * @param list<string>         $trackers
     * @param list<string>         $tags
     * @param array<string, mixed> $llmJson
     */
    private function assistant(array $trackers, array $tags, array $llmJson): TicketTriageAssistant
    {
        $ws = new Workspace();

        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(true);
        $llm->method('completeJson')->willReturn($llmJson);
        $llm->method('getModel')->willReturn('claude-test');

        $trackerRepo = $this->createStub(TrackerRepository::class);
        $trackerRepo->method('findBy')->willReturn(array_map(
            static fn (string $n): Tracker => (new Tracker())->setName($n)->setWorkspace($ws),
            $trackers,
        ));

        $tagRepo = $this->createStub(TagRepository::class);
        $tagRepo->method('findBy')->willReturn(array_map(
            static fn (string $n): Tag => (new Tag())->setName($n)->setScope(TagScope::Any)->setWorkspace($ws),
            $tags,
        ));

        $commentRepo = $this->createStub(CommentRepository::class);
        $commentRepo->method('findBy')->willReturn([]);

        return new TicketTriageAssistant(
            $llm,
            $trackerRepo,
            $tagRepo,
            $commentRepo,
            $this->createStub(EntityManagerInterface::class),
            new AiUsageContext(),
        );
    }

    private function task(): Task
    {
        return (new Task())
            ->setTitle('Login liefert 500')
            ->setDescription('Seit heute morgen.')
            ->setWorkspace(new Workspace());
    }

    public function testSuggestTicketReturnsDecisionTitleAndSummary(): void
    {
        $assistant = $this->conversationAssistant([
            'shouldCreateTicket' => true,
            'title' => 'Login behebt 500',
            'summary' => 'Kunde kann sich nicht anmelden.',
            'reasoning' => 'Konkretes Problem.',
        ]);

        $out = $assistant->suggestTicketForConversation((new Conversation())->setWorkspace(new Workspace())->setSubject('Login kaputt'));

        self::assertTrue($out['suggestion']['shouldCreateTicket']);
        self::assertSame('Login behebt 500', $out['suggestion']['title']);
        self::assertSame('Kunde kann sich nicht anmelden.', $out['suggestion']['summary']);
    }

    public function testSuggestTicketFalsyDecisionAndTitleFallsBackToSubject(): void
    {
        $assistant = $this->conversationAssistant(['shouldCreateTicket' => false]);

        $out = $assistant->suggestTicketForConversation((new Conversation())->setWorkspace(new Workspace())->setSubject('Danke!'));

        self::assertFalse($out['suggestion']['shouldCreateTicket']);
        self::assertSame('Danke!', $out['suggestion']['title']); // empty title → subject
    }

    /**
     * @param array<string, mixed> $llmJson
     */
    private function conversationAssistant(array $llmJson): TicketTriageAssistant
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(true);
        $llm->method('completeJson')->willReturn($llmJson);
        $llm->method('getModel')->willReturn('claude-test');

        $eventRepo = $this->createStub(EntityRepository::class);
        $eventRepo->method('findBy')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($eventRepo);

        return new TicketTriageAssistant(
            $llm,
            $this->createStub(TrackerRepository::class),
            $this->createStub(TagRepository::class),
            $this->createStub(CommentRepository::class),
            $em,
            new AiUsageContext(),
        );
    }
}
