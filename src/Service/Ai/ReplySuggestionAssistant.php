<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Conversation;
use App\Entity\InboundEvent;
use App\Entity\SavedReply;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Llm\LlmProviderInterface;

/**
 * Drafts a reply to a customer conversation, using the workspace's Saved Replies
 * as few-shot examples of house tone/wording. Returns free text only — nothing
 * is persisted or sent: the SPA drops the draft into the reply composer for the
 * agent to edit and send (same inline, human-in-the-loop idea as
 * {@see TagSuggestionAssistant}, not the async AIRecommendation envelope, since a
 * queued OutboundMessage would risk actually sending).
 */
final class ReplySuggestionAssistant
{
    private const MAX_EVENTS = 12;
    private const MAX_SAVED_REPLIES = 8;
    private const MAX_TEXT = 5000;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly EntityManagerInterface $em,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /** Draft a reply body for the conversation. Returns the trimmed suggestion text. */
    public function suggestReply(Conversation $conversation): string
    {
        $reply = $this->llm->complete(
            $this->systemPrompt($conversation),
            $this->buildContext($conversation),
            1024,
        );

        return trim($reply);
    }

    private function systemPrompt(Conversation $conversation): string
    {
        $examples = $this->savedReplyExamples($conversation);

        $base = <<<PROMPT
        You are a customer-support agent drafting a reply to a customer conversation.
        Write a helpful, concise, professional reply in the SAME LANGUAGE as the
        customer's messages. Address their request directly; do not invent facts,
        prices, dates, or commitments you cannot see in the thread. Output ONLY the
        reply body — no subject line, no "Dear ..."/signature block unless the
        examples show one, no surrounding quotes or markdown fences.
        PROMPT;

        if ($examples !== '') {
            $base .= "\n\nHouse saved replies (match this tone and phrasing where relevant):\n" . $examples;
        }

        return $base;
    }

    /** Workspace Saved Replies, rendered as compact few-shot examples. */
    private function savedReplyExamples(Conversation $conversation): string
    {
        /** @var list<SavedReply> $replies */
        $replies = $this->em->getRepository(SavedReply::class)->findBy(
            ['workspace' => $conversation->getWorkspace()],
            ['createdAt' => 'DESC'],
            self::MAX_SAVED_REPLIES,
        );

        $lines = [];
        foreach ($replies as $reply) {
            $body = trim($reply->getBody());
            if ($body === '') {
                continue;
            }
            $lines[] = sprintf('- "%s": %s', $reply->getName(), $body);
        }

        return implode("\n", $lines);
    }

    private function buildContext(Conversation $conversation): string
    {
        $parts = ['Subject: ' . $conversation->getSubject()];

        /** @var list<InboundEvent> $events */
        $events = $this->em->getRepository(InboundEvent::class)->findBy(
            ['conversation' => $conversation],
            ['receivedAt' => 'ASC'],
            self::MAX_EVENTS,
        );
        foreach ($events as $event) {
            $body = trim((string) $event->getBody());
            if ($body !== '') {
                $parts[] = 'Message from ' . ($event->getSenderRaw() ?? 'customer') . ':' . "\n" . $body;
            }
        }

        $parts[] = "\nDraft the next reply to the customer.";

        return mb_substr(implode("\n\n", $parts), 0, self::MAX_TEXT);
    }
}
