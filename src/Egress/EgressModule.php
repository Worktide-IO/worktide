<?php

declare(strict_types=1);

namespace App\Egress;

/**
 * The categories of outbound data egress that {@see EgressGuard} gates.
 *
 * Each value is a "module" the operator approves explicitly via the
 * `EGRESS_ALLOW` config. Default is deny: nothing in this enum may leave the
 * system until its module is listed. The string values are the tokens used in
 * `EGRESS_ALLOW` (e.g. `EGRESS_ALLOW="llm,ticket_push:<channelUuid>"`).
 *
 * Deliberately NOT gated (and therefore not listed here): OAuth token exchange
 * (an auth handshake for inbound mail, carries no business data) and Mercure
 * publishing (our own real-time hub for the SPA). Inbound pulls/reads are not
 * egress at all.
 */
enum EgressModule: string
{
    case EmailOutbound = 'email_outbound';
    case SocialPublish = 'social_publish';
    case WebhookDelivery = 'webhook_delivery';
    case TicketPush = 'ticket_push';
    case Llm = 'llm';
    case ExternalSearch = 'external_search'; // research agent: outbound web/company search
}
