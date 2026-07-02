<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\InboundEvent;

/**
 * Cheap, LLM-free heuristic that decides whether an inbound e-mail is worth an
 * AI ticket suggestion. Newsletters, bulk mail and automated notifications are
 * NOT actionable — they stay visible in the inbox but never trigger an LLM call
 * (cost control) nor a ticket suggestion.
 *
 * Reads the headers captured by {@see \App\Channels\Adapter\Email\EmailImapAdapter}
 * into InboundEvent.sourceMetadata['headers'].
 */
final class MailRelevanceClassifier
{
    /** Sender local-parts / roles that never warrant a ticket. */
    private const NON_HUMAN_SENDER = '/(?:^|[\s<])(?:no-?reply|do-?not-?reply|donotreply|mailer-daemon|postmaster|bounce[+-]?|notifications?|newsletter)@/i';

    public function isActionable(InboundEvent $event): bool
    {
        $md = $event->getSourceMetadata();
        $headers = \is_array($md['headers'] ?? null) ? $md['headers'] : [];

        // 1. Newsletter — RFC 2369 unsubscribe header is the strongest signal.
        if (($headers['List-Unsubscribe'] ?? null) !== null && (string) $headers['List-Unsubscribe'] !== '') {
            return false;
        }

        // 2. Bulk / list precedence.
        $precedence = strtolower(trim((string) ($headers['Precedence'] ?? '')));
        if (\in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return false;
        }

        // 3. Auto-generated / auto-replied (RFC 3834). "no" means a real reply.
        $autoSubmitted = strtolower(trim((string) ($headers['Auto-Submitted'] ?? '')));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return false;
        }

        // 4. Non-human sender address (noreply@, mailer-daemon@, …).
        $from = (string) ($headers['From'] ?? $event->getSenderRaw() ?? '');
        if ($from !== '' && preg_match(self::NON_HUMAN_SENDER, $from) === 1) {
            return false;
        }

        return true;
    }
}
