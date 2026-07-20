<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\Enum\InboundMuteMatchType;
use App\Entity\InboundEvent;
use App\Entity\InboundMuteRule;
use App\Repository\InboundMuteRuleRepository;

/**
 * Evaluates a workspace's {@see InboundMuteRule}s against an inbound event.
 *
 * A match flags the event's Conversation with `mutedAt` (kept fully stored +
 * searchable, just out of the default inbox) and bumps the rule's hit counter.
 * The caller ({@see InboundEventProcessor}) then skips auto-reply / AI
 * suggestion / automation dispatch for muted events.
 */
final class InboundMuteMatcher
{
    public function __construct(
        private readonly InboundMuteRuleRepository $rules,
    ) {}

    /**
     * @return bool true when a rule matched (and the conversation was flagged)
     */
    public function matchAndFlag(InboundEvent $event, \DateTimeImmutable $now): bool
    {
        $workspace = $event->getChannel()->getWorkspace();
        $rules = $this->rules->findEnabledForWorkspace($workspace);
        if ($rules === []) {
            return false;
        }

        $senderEmail = $this->senderEmail($event);
        $subject = (string) $event->getSubject();

        foreach ($rules as $rule) {
            if (!$this->ruleMatches($rule, $senderEmail, $subject)) {
                continue;
            }
            $rule->registerHit($now);
            $conversation = $event->getConversation();
            if ($conversation !== null && $conversation->getMutedAt() === null) {
                $conversation->setMutedAt($now);
            }

            return true;
        }

        return false;
    }

    public function ruleMatches(InboundMuteRule $rule, ?string $senderEmail, string $subject): bool
    {
        return match ($rule->getMatchType()) {
            InboundMuteMatchType::SenderEmail => $senderEmail !== null
                && $senderEmail === mb_strtolower(trim($rule->getValue())),
            InboundMuteMatchType::SubjectContains => trim($rule->getValue()) !== ''
                && mb_stripos($subject, trim($rule->getValue())) !== false,
        };
    }

    /**
     * Best-effort sender e-mail: prefer the raw From header, fall back to the
     * captured senderRaw. Handles both "Name <a@b>" and a bare address.
     */
    public function senderEmail(InboundEvent $event): ?string
    {
        $candidates = [];
        $from = $event->getSourceMetadata()['headers']['From'] ?? null;
        if (\is_string($from) && $from !== '') {
            $candidates[] = $from;
        }
        if ($event->getSenderRaw() !== null) {
            $candidates[] = $event->getSenderRaw();
        }

        foreach ($candidates as $raw) {
            $email = $this->emailFromRaw($raw);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    /** Extract a lower-cased e-mail from a raw "Name <a@b>" or bare-address string. */
    public function emailFromRaw(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
            $raw = $m[1];
        }
        $raw = trim($raw);

        return filter_var($raw, \FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($raw) : null;
    }
}
