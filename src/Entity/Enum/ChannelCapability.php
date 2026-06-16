<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What a {@see \App\Entity\Channel} can do for the workspace.
 *
 *   Inbound  — Worktide receives events from this channel (mail
 *              pull, webhook ingest, …).
 *   Outbound — Worktide sends messages out through this channel.
 *
 * A single channel may carry both. Emails are the typical
 * both-capable case (SMTP-Send + IMAP-Pull). A Zabbix webhook is
 * inbound-only; a transactional Mailgun setup is outbound-only.
 */
enum ChannelCapability: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
