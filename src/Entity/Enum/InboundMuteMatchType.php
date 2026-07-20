<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How an {@see \App\Entity\InboundMuteRule} matches an incoming message.
 */
enum InboundMuteMatchType: string
{
    /** Exact (case-insensitive) match on the sender's e-mail address. */
    case SenderEmail = 'sender_email';

    /** Case-insensitive substring match on the subject. */
    case SubjectContains = 'subject_contains';
}
