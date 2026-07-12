<?php

declare(strict_types=1);

namespace App\Tests\Service\PromptInjection;

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
use App\Tests\Support\PromptInjectionPayloads;
use App\Tests\Support\RecordingLlmProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Prompt-injection resilience for staff-side triage. This is the "malicious
 * email / ticket" vector the user cares about: the untrusted text is the ticket
 * title/description/comments (triageTask) or the conversation subject and
 * inbound email bodies (triageConversation).
 *
 * Guardrails: the triage output can only ever carry values that really exist in
 * the workspace — an injected/hijacked tracker, priority or status is dropped,
 * and invented tags are quarantined into suggestedNewTags. And the untrusted
 * text never contaminates the system prompt.
 */
final class TicketTriageInjectionTest extends TestCase
{
    public function testInjectedTicketTextNeverReachesTheSystemPrompt(): void
    {
        foreach (PromptInjectionPayloads::all() as $name => $payload) {
            $llm = new RecordingLlmProvider(['summary' => 'x', 'tracker' => null, 'priority' => null, 'tags' => []]);
            $task = (new Task())->setTitle($payload)->setDescription($payload)->setWorkspace(new Workspace());
            $this->assistant($llm)->triageTask($task);

            self::assertStringNotContainsString(
                PromptInjectionPayloads::MARKER,
                $llm->lastSystem(),
                "payload '{$name}' leaked into the triage system prompt",
            );
            self::assertStringContainsString(PromptInjectionPayloads::MARKER, $llm->lastUser());
        }
    }

    public function testHijackedModelCannotForgeTrackerPriorityOrTags(): void
    {
        // The model "obeyed" the injection embedded in the ticket text.
        $llm = new RecordingLlmProvider([
            'summary' => 'irrelevant',
            'tracker' => '__admin_override__',   // not a configured tracker
            'priority' => 'exfiltrate-now',       // not a TaskPriority
            'tags' => ['auth', 'make-me-admin'],  // one real, one forged
        ]);

        $task = (new Task())
            ->setTitle(PromptInjectionPayloads::TOOL_ABUSE)
            ->setDescription(PromptInjectionPayloads::EXFIL_EMAIL)
            ->setWorkspace(new Workspace());

        $s = $this->assistant($llm)->triageTask($task)['suggestion'];

        self::assertNull($s['tracker'], 'forged tracker must be dropped');
        self::assertNull($s['priority'], 'forged priority must be dropped');
        self::assertSame(['auth'], $s['tags'], 'only real tags may be applied');
        self::assertSame(['make-me-admin'], $s['suggestedNewTags']);
    }

    public function testInjectedConversationSubjectNeverReachesTheSystemPrompt(): void
    {
        // The email/conversation vector: subject is attacker-controlled.
        $llm = new RecordingLlmProvider(['shouldCreateTicket' => false]);
        $conversation = (new Conversation())->setSubject(PromptInjectionPayloads::FAKE_SYSTEM);

        $this->conversationAssistant($llm)->suggestTicketForConversation($conversation);

        self::assertStringNotContainsString(PromptInjectionPayloads::MARKER, $llm->lastSystem());
        self::assertStringContainsString(PromptInjectionPayloads::MARKER, $llm->lastUser());
    }

    private function assistant(RecordingLlmProvider $llm): TicketTriageAssistant
    {
        $ws = new Workspace();

        $trackerRepo = $this->createStub(TrackerRepository::class);
        $trackerRepo->method('findBy')->willReturn([(new Tracker())->setName('Bug')->setWorkspace($ws)]);

        $tagRepo = $this->createStub(TagRepository::class);
        $tagRepo->method('findBy')->willReturn([(new Tag())->setName('auth')->setScope(TagScope::Any)->setWorkspace($ws)]);

        $commentRepo = $this->createStub(CommentRepository::class);
        $commentRepo->method('findBy')->willReturn([]);

        return new TicketTriageAssistant(
            $llm,
            $trackerRepo,
            $tagRepo,
            $commentRepo,
            $this->createStub(EntityManagerInterface::class),
        );
    }

    private function conversationAssistant(RecordingLlmProvider $llm): TicketTriageAssistant
    {
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
        );
    }
}
