<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Comment;
use App\Entity\Conversation;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\ConversationStatus;
use App\Entity\Enum\TagScope;
use App\Entity\Enum\TaskPriority;
use App\Entity\InboundEvent;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\Tracker;
use App\Repository\CommentRepository;
use App\Repository\TagRepository;
use App\Repository\TrackerRepository;
use App\Service\Llm\AiUsageContext;
use App\Service\Llm\LlmProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Produces triage suggestions for a ticket (Task or Conversation): a short
 * summary plus, for Tasks, a proposed Tracker / Priority / Tags — for
 * Conversations, a proposed status. Mirrors {@see \App\Service\Social\SocialPostAiAssistant}:
 * suggestions only, never applied here (human-in-the-loop via
 * {@see RecommendationApplier}).
 *
 * The model must choose from the workspace's REAL options (tracker names, tag
 * names, enum values are listed in the prompt); anything it invents is dropped
 * during validation so a suggestion can always be applied cleanly.
 */
final class TicketTriageAssistant
{
    private const MAX_COMMENTS = 20;
    private const MAX_EVENTS = 20;
    private const MAX_TEXT = 4000;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly TrackerRepository $trackers,
        private readonly TagRepository $tags,
        private readonly CommentRepository $comments,
        private readonly EntityManagerInterface $em,
        private readonly AiUsageContext $usageContext,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    /** The model that produced a suggestion — stored on the recommendation for provenance. */
    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * @return array{suggestion: array<string, mixed>, reasoning: ?string}
     */
    public function triageTask(Task $task): array
    {
        $workspace = $task->getWorkspace();

        $trackerNames = array_map(
            static fn (Tracker $t): string => $t->getName(),
            $this->trackers->findBy(['workspace' => $workspace]),
        );
        $tagNames = array_values(array_map(
            static fn (Tag $t): string => $t->getName(),
            array_filter(
                $this->tags->findBy(['workspace' => $workspace]),
                static fn (Tag $t): bool => \in_array($t->getScope(), [TagScope::Task, TagScope::Any], true),
            ),
        ));
        $priorities = array_map(static fn (TaskPriority $p): string => $p->value, TaskPriority::cases());

        $system = $this->taskSystemPrompt($trackerNames, $tagNames, $priorities);
        $user = $this->buildTaskContext($task);

        $this->usageContext->set('triage', $workspace);
        $raw = $this->llm->completeJson($system, $user);

        $suggestion = [
            'summary' => $this->cleanSummary($raw['summary'] ?? null),
            'tracker' => $this->matchName($raw['tracker'] ?? null, $trackerNames),
            'priority' => $this->matchEnum($raw['priority'] ?? null, $priorities),
            'tags' => $this->matchList($raw['tags'] ?? null, $tagNames, $unknownTags),
            'suggestedNewTags' => $unknownTags,
        ];

        return ['suggestion' => $suggestion, 'reasoning' => $this->cleanReasoning($raw['reasoning'] ?? null)];
    }

    /**
     * @return array{suggestion: array<string, mixed>, reasoning: ?string}
     */
    public function triageConversation(Conversation $conversation): array
    {
        $statuses = array_map(static fn (ConversationStatus $s): string => $s->value, ConversationStatus::cases());

        $system = $this->conversationSystemPrompt($statuses);
        $user = $this->buildConversationContext($conversation);

        $this->usageContext->set('triage', $conversation->getWorkspace());
        $raw = $this->llm->completeJson($system, $user);

        $suggestion = [
            'summary' => $this->cleanSummary($raw['summary'] ?? null),
            'status' => $this->matchEnum($raw['status'] ?? null, $statuses),
        ];

        return ['suggestion' => $suggestion, 'reasoning' => $this->cleanReasoning($raw['reasoning'] ?? null)];
    }

    /**
     * Decide whether a conversation warrants a work ticket and, if so, a title +
     * summary. The relevance heuristic already dropped newsletters/automated
     * mail; this filters the remaining "thank you / FYI" replies that don't need
     * a ticket.
     *
     * @return array{suggestion: array{shouldCreateTicket: bool, title: string, summary: string}, reasoning: ?string}
     */
    public function suggestTicketForConversation(Conversation $conversation): array
    {
        $this->usageContext->set('ticket_from_conversation', $conversation->getWorkspace());
        $raw = $this->llm->completeJson($this->ticketSuggestionSystemPrompt(), $this->buildConversationContext($conversation));

        $title = trim((string) ($raw['title'] ?? ''));
        if ($title === '') {
            $title = $conversation->getSubject();
        }

        return [
            'suggestion' => [
                'shouldCreateTicket' => (bool) ($raw['shouldCreateTicket'] ?? false),
                'title' => mb_substr($title, 0, 200),
                'summary' => $this->cleanSummary($raw['summary'] ?? null),
            ],
            'reasoning' => $this->cleanReasoning($raw['reasoning'] ?? null),
        ];
    }

    // -- context builders -----------------------------------------------------

    private function buildTaskContext(Task $task): string
    {
        $parts = [
            'Title: ' . $task->getTitle(),
            'Description: ' . (trim((string) $task->getDescription()) !== '' ? $task->getDescription() : '(none)'),
        ];

        /** @var list<Comment> $comments */
        $comments = $this->comments->findBy(
            ['target' => CommentTarget::Task, 'targetId' => $task->getId()],
            ['createdAt' => 'ASC'],
            self::MAX_COMMENTS,
        );
        foreach ($comments as $c) {
            $parts[] = 'Comment: ' . $c->getContent();
        }

        return $this->cap(implode("\n\n", $parts));
    }

    private function buildConversationContext(Conversation $conversation): string
    {
        $parts = ['Subject: ' . $conversation->getSubject()];

        /** @var list<InboundEvent> $events */
        $events = $this->em->getRepository(InboundEvent::class)->findBy(
            ['conversation' => $conversation],
            ['receivedAt' => 'ASC'],
            self::MAX_EVENTS,
        );
        foreach ($events as $e) {
            $body = trim((string) $e->getBody());
            if ($body !== '') {
                $parts[] = 'Message from ' . ($e->getSenderRaw() ?? 'customer') . ': ' . $body;
            }
        }

        return $this->cap(implode("\n\n", $parts));
    }

    // -- prompts --------------------------------------------------------------

    /**
     * @param list<string> $trackers
     * @param list<string> $tags
     * @param list<string> $priorities
     */
    private function taskSystemPrompt(array $trackers, array $tags, array $priorities): string
    {
        $trackerList = $trackers === [] ? '(none configured)' : implode(', ', $trackers);
        $tagList = $tags === [] ? '(none configured)' : implode(', ', $tags);
        $priorityList = implode(', ', $priorities);

        return <<<PROMPT
        You are a support/project triage assistant. Given a ticket (title, description, comments),
        propose how to classify it. Be concise and factual; do not invent details.

        Respond as a JSON object with these keys:
        - "summary": one or two sentences summarising the ticket in the ticket's own language.
        - "tracker": the single best-fitting tracker, chosen ONLY from: {$trackerList}. Use null if none fits.
        - "priority": one of exactly: {$priorityList}.
        - "tags": an array of the most relevant tags, chosen ONLY from: {$tagList}. Empty array if none fit.
        - "reasoning": one short sentence explaining the classification.
        PROMPT;
    }

    /**
     * @param list<string> $statuses
     */
    private function conversationSystemPrompt(array $statuses): string
    {
        $statusList = implode(', ', $statuses);

        return <<<PROMPT
        You are a helpdesk triage assistant. Given an email conversation (subject and messages),
        propose how to handle it. Be concise and factual; do not invent details.

        Respond as a JSON object with these keys:
        - "summary": one or two sentences summarising the conversation in its own language.
        - "status": one of exactly: {$statusList}. Use "spam" only for clear spam.
        - "reasoning": one short sentence explaining the choice.
        PROMPT;
    }

    private function ticketSuggestionSystemPrompt(): string
    {
        return <<<PROMPT
        You are a support triage assistant. Decide whether an email conversation warrants
        creating a work ticket — i.e. a customer request, problem, or task that needs
        action — versus not (a thank-you, an FYI/confirmation, small talk, out-of-office).

        Respond as a JSON object with these keys:
        - "shouldCreateTicket": boolean.
        - "title": a short, actionable ticket title in the conversation's language (max ~80 chars).
        - "summary": one or two sentences describing what needs to be done.
        - "reasoning": one short sentence explaining the decision.
        PROMPT;
    }

    // -- validation helpers ---------------------------------------------------

    private function cleanSummary(mixed $value): string
    {
        $s = \is_string($value) ? trim($value) : '';

        return mb_substr($s, 0, 1000);
    }

    private function cleanReasoning(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s === '' ? null : mb_substr($s, 0, 1000);
    }

    /**
     * Match a model-proposed name (case-insensitive) against known values,
     * returning the canonical known value or null.
     *
     * @param list<string> $known
     */
    private function matchName(mixed $value, array $known): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $needle = mb_strtolower(trim($value));
        foreach ($known as $candidate) {
            if (mb_strtolower($candidate) === $needle) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $allowed
     */
    private function matchEnum(mixed $value, array $allowed): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $v = mb_strtolower(trim($value));

        return \in_array($v, $allowed, true) ? $v : null;
    }

    /**
     * Split a proposed tag list into known (canonical) tags and unknown ones.
     *
     * @param list<string> $known
     * @param list<string> $unknown out-param: names not matching any known tag
     * @return list<string>
     */
    private function matchList(mixed $value, array $known, ?array &$unknown): array
    {
        $unknown = [];
        if (!\is_array($value)) {
            return [];
        }

        $matched = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                continue;
            }
            $canonical = $this->matchName($item, $known);
            if ($canonical !== null) {
                $matched[$canonical] = true;
            } else {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $unknown[] = $trimmed;
                }
            }
        }
        $unknown = array_values(array_unique($unknown));

        return array_keys($matched);
    }

    private function cap(string $text): string
    {
        return mb_substr($text, 0, self::MAX_TEXT);
    }
}
