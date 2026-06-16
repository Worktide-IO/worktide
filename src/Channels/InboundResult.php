<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\InboundEvent;

/**
 * What an {@see InboundAdapter::pull()} or
 * {@see InboundAdapter::consumeWebhook()} call returns: the list of
 * brand-new InboundEvents the adapter persisted (post-dedup), plus
 * a cursor it wants to remember for the next call.
 *
 * Cursor semantics are adapter-specific (IMAP last UID, Gmail
 * historyId, slack since-ts). The {@see ChannelRunner} writes it
 * back to Channel.inboundConfig['cursor'] so the next poll resumes
 * from there.
 */
final class InboundResult
{
    /**
     * @param list<InboundEvent> $events
     */
    public function __construct(
        public readonly array $events = [],
        public readonly ?string $cursor = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }
}
