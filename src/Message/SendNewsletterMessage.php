<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Queued instruction to mail one newsletter issue to one contact. The send
 * controller fans one of these out per recipient so a slow mail server never
 * blocks the request and each recipient retries independently.
 */
final class SendNewsletterMessage
{
    public function __construct(
        private readonly Uuid $issueId,
        private readonly Uuid $contactId,
    ) {}

    public function getIssueId(): Uuid
    {
        return $this->issueId;
    }

    public function getContactId(): Uuid
    {
        return $this->contactId;
    }
}
