<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\Conversation;
use App\Entity\Enum\InboundRuleCombinator;
use App\Entity\Enum\InboundRuleField;
use App\Entity\Enum\InboundRuleOperator;
use App\Entity\InboundEvent;
use App\Entity\InboundMuteRule;
use App\Repository\InboundMuteRuleRepository;

/**
 * Evaluates a workspace's {@see InboundMuteRule}s (Thunderbird-style condition
 * lists) against an inbound event. A match flags the event's Conversation with
 * `mutedAt` (kept + searchable, just out of the default inbox) and bumps the
 * rule's hit counter; the caller ({@see InboundEventProcessor}) then skips
 * auto-reply / AI suggestion / n8n dispatch for muted events.
 *
 * Field values that are unavailable resolve to `null` → the condition is
 * conservatively treated as NOT matching (never mute on missing data).
 */
final class InboundMuteMatcher
{
    public function __construct(
        private readonly InboundMuteRuleRepository $rules,
    ) {}

    public function matchAndFlag(InboundEvent $event, \DateTimeImmutable $now): bool
    {
        $rules = $this->rules->findEnabledForWorkspace($event->getChannel()->getWorkspace());
        if ($rules === []) {
            return false;
        }
        $fields = $this->fieldsFromEvent($event);
        foreach ($rules as $rule) {
            if (!$this->ruleMatches($rule, $fields)) {
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

    /**
     * @param array<string, string|null> $fields field value → actual (null = unavailable)
     */
    public function ruleMatches(InboundMuteRule $rule, array $fields): bool
    {
        $conditions = $rule->getConditions();
        if ($conditions === []) {
            return false; // never mute-all
        }
        $isAnd = $rule->getCombinator() === InboundRuleCombinator::And;
        foreach ($conditions as $condition) {
            $ok = $this->conditionMatches($condition, $fields);
            if ($isAnd && !$ok) {
                return false;
            }
            if (!$isAnd && $ok) {
                return true;
            }
        }

        return $isAnd; // AND: all passed; OR: none passed
    }

    /**
     * @param array{field?: string, operator?: string, value?: string} $condition
     * @param array<string, string|null>                               $fields
     */
    private function conditionMatches(array $condition, array $fields): bool
    {
        $field = InboundRuleField::tryFrom((string) ($condition['field'] ?? ''));
        $operator = InboundRuleOperator::tryFrom((string) ($condition['operator'] ?? ''));
        if ($field === null || $operator === null) {
            return false;
        }
        $actual = $fields[$field->value] ?? null;
        if ($actual === null) {
            return false; // field unavailable → conservative no-match
        }
        $needle = (string) ($condition['value'] ?? '');
        $h = mb_strtolower($actual);
        $n = mb_strtolower($needle);

        return match ($operator) {
            InboundRuleOperator::Contains => $n !== '' && str_contains($h, $n),
            InboundRuleOperator::NotContains => $n === '' || !str_contains($h, $n),
            InboundRuleOperator::Equals => $h === $n,
            InboundRuleOperator::NotEquals => $h !== $n,
            InboundRuleOperator::StartsWith => $n !== '' && str_starts_with($h, $n),
            InboundRuleOperator::EndsWith => $n !== '' && str_ends_with($h, $n),
            InboundRuleOperator::Regex => $this->regexMatch($needle, $actual),
        };
    }

    private function regexMatch(string $pattern, string $subject): bool
    {
        if ($pattern === '') {
            return false;
        }
        $delimited = '/' . str_replace('/', '\\/', $pattern) . '/i';
        set_error_handler(static fn (): bool => true);
        try {
            $result = preg_match($delimited, $subject);
        } finally {
            restore_error_handler();
        }

        return $result === 1;
    }

    /**
     * @return array<string, string|null>
     */
    public function fieldsFromEvent(InboundEvent $event): array
    {
        return [
            InboundRuleField::SenderEmail->value => $this->senderEmail($event),
            InboundRuleField::Subject->value => (string) $event->getSubject(),
            InboundRuleField::Body->value => (string) $event->getBody(),
            InboundRuleField::ChannelAdapter->value => $event->getChannel()->getAdapterCode(),
        ];
    }

    /**
     * Fields from a Conversation (for back-filling existing threads). `body`
     * lives on events, not the conversation → null → body conditions won't
     * back-fill (they still apply to new messages at ingest).
     *
     * @return array<string, string|null>
     */
    public function fieldsFromConversation(Conversation $conversation): array
    {
        return [
            InboundRuleField::SenderEmail->value => $this->emailFromRaw($conversation->getSenderRaw()),
            InboundRuleField::Subject->value => $conversation->getSubject(),
            InboundRuleField::Body->value => null,
            InboundRuleField::ChannelAdapter->value => $conversation->getChannel()->getAdapterCode(),
        ];
    }

    /**
     * Validate + normalise a raw conditions payload into storable rows. Shared
     * by the mute-sender + automation rule endpoints.
     *
     * @return list<array{field: string, operator: string, value: string}>
     *
     * @throws \InvalidArgumentException on an empty/invalid condition set
     */
    public function normalizeConditions(mixed $raw): array
    {
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('conditions must be a list.');
        }
        $out = [];
        foreach ($raw as $c) {
            if (!\is_array($c)) {
                continue;
            }
            $field = InboundRuleField::tryFrom((string) ($c['field'] ?? ''));
            $operator = InboundRuleOperator::tryFrom((string) ($c['operator'] ?? ''));
            $value = trim((string) ($c['value'] ?? ''));
            if ($field === null) {
                throw new \InvalidArgumentException('Unknown condition field.');
            }
            if ($operator === null) {
                throw new \InvalidArgumentException('Unknown condition operator.');
            }
            if ($value === '') {
                throw new \InvalidArgumentException('Condition value is required.');
            }
            $out[] = ['field' => $field->value, 'operator' => $operator->value, 'value' => $value];
        }
        if ($out === []) {
            throw new \InvalidArgumentException('At least one condition is required.');
        }

        return $out;
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
