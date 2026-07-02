<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to have the AI assess a Conversation and, if actionable,
 * produce a Pending "create ticket?" {@see \App\Entity\AIRecommendation}
 * (kind = TicketFromConversation).
 *
 * Routed to the dedicated `ai_agents` transport (slow, rate-limited LLM work).
 * Dispatched automatically for live shared-mailbox mail that passes the
 * relevance heuristic, or on demand via the conversation endpoint. Carries only
 * the conversation id; the handler re-loads and supersedes stale suggestions.
 */
final class SuggestConversationTicketMessage
{
    public function __construct(
        private readonly Uuid $conversationId,
    ) {}

    public function getConversationId(): Uuid
    {
        return $this->conversationId;
    }
}
