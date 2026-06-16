<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Channel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Implemented by every concrete channel that *receives* events from
 * the outside world — mail mailboxes, slack bots, webhook receivers,
 * polling adapters for RSS/CVE feeds, etc.
 *
 * Two ingest modes, an adapter implements one or both:
 *
 *   - **pull()**: scheduled adapters (IMAP, RSS, periodic API poll).
 *     The {@see ChannelRunner} (Phase C.2 worker) calls this on a
 *     timer and persists the resulting events.
 *
 *   - **consumeWebhook(Request)**: push adapters (Graph subscription,
 *     Gmail watch + Pub/Sub, slack events-api, zabbix webhook). A
 *     route in `WebhookController` resolves the channel from the
 *     URL token and delegates the parsed request here.
 *
 * Implementations should be **idempotent** — pulling the same source
 * twice must not create duplicate events. Persistence + dedup go
 * through {@see InboundEventRepository::findByExternalId()}.
 *
 * Implementations are tagged services discovered by
 * {@see AdapterRegistry}. The tag's `adapterCode` attribute must
 * match {@see getCode()}.
 */
interface InboundAdapter
{
    /**
     * Stable identifier for this adapter — `email_imap`, `email_graph`,
     * `email_gmail`, `slack_bot`, `zabbix_webhook`, …
     *
     * The Channel.adapterCode column stores exactly this string; the
     * registry uses it to dispatch a Channel row to its adapter.
     */
    public function getCode(): string;

    /**
     * Optional human-readable label (admin UI dropdown). Defaults
     * to a derivation of `getCode()` when unset.
     */
    public function getLabel(): string;

    /**
     * Periodic ingest — called by the ChannelRunner cron. Adapters
     * that have nothing to pull (purely push-based) may throw
     * {@see PullNotSupportedException} or return an empty result.
     *
     * The cursor from the previous call lives in
     * `$channel->getInboundConfig()['cursor']` (read inside the
     * adapter). The returned `InboundResult.cursor` is written back
     * after the call by the runner.
     */
    public function pull(Channel $channel): InboundResult;

    /**
     * Push ingest — called by the webhook controller when the
     * configured push endpoint receives a request that resolved to
     * this channel. Adapters that don't accept pushes throw
     * {@see WebhookNotSupportedException}.
     */
    public function consumeWebhook(Channel $channel, Request $request): InboundResult;
}
