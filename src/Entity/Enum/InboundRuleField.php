<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * The message field an {@see \App\Entity\InboundMuteRule} condition inspects.
 * Thunderbird-style filter fields, narrowed to what an inbound event carries.
 */
enum InboundRuleField: string
{
    case SenderEmail = 'sender_email';
    case Subject = 'subject';
    case Body = 'body';
    case ChannelAdapter = 'channel_adapter';
}
