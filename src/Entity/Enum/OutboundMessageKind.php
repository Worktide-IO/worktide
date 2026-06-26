<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What an {@see \App\Entity\OutboundMessage} is, within a conversation thread:
 *
 *   Reply   — a normal answer to the customer (default).
 *   Forward — the conversation is forwarded to a third party.
 *
 * Distinguishes the `message` vs `forward` thread types without a separate
 * entity — the recipients differ, the mechanics are the same.
 */
enum OutboundMessageKind: string
{
    case Reply = 'reply';
    case Forward = 'forward';
}
