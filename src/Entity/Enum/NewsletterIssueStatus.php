<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Lifecycle of a {@see \App\Entity\NewsletterIssue}: composed as a draft, then
 * sent once to the node's opted-in contacts. Terminal — a sent issue is
 * read-only (duplicate to send again).
 */
enum NewsletterIssueStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
}
