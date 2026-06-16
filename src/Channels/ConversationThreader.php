<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\InboundEvent;

/**
 * Pluggable threading strategy for a channel.
 *
 * Threading-capable channels (mail, slack, whatsapp) implement a
 * concrete strategy that turns a freshly ingested InboundEvent
 * (still un-conversation'd) into either:
 *   - a hit against an existing Conversation row (the event joins
 *     the thread), or
 *   - a brand-new Conversation row (no match, start a thread).
 *
 * Stateless channels (zabbix, cve-feed, generic webhook) don't have
 * a threader and leave InboundEvent.conversation = null.
 *
 * The contract is intentionally small — strategies do their own
 * lookup logic (mail walks the References chain, slack uses
 * thread_ts) and the runner just delegates.
 */
interface ConversationThreader
{
    /**
     * Resolve or create the Conversation for this freshly-built
     * event. Implementations MAY persist new Conversation rows;
     * the caller flushes after.
     *
     * Sets event.conversation as a side effect (caller doesn't
     * need to do it).
     */
    public function attach(Channel $channel, InboundEvent $event): Conversation;
}
