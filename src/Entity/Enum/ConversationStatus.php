<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of an inbound Conversation (FreeScout-inspired).
 *
 *   Open    — needs action, shows up in the inbox by default
 *   Pending — waiting on the customer or a third party
 *   Closed  — resolved; archive, but searchable
 *   Spam    — out of inbox view, kept for audit
 */
enum ConversationStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Closed = 'closed';
    case Spam = 'spam';
}
