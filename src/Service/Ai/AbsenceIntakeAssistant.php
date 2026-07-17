<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Workspace;
use App\Service\Llm\AiUsageContext;
use App\Service\Llm\LlmProviderInterface;

/**
 * Parses a free-text staff absence note ("bin heute und morgen krank") into a
 * structured absence, resolving relative dates from today and asking ONE
 * clarifying question when the range is ambiguous. Suggestion only — the
 * controller confirms + persists. Feature "absence_intake" for usage/budget.
 */
final class AbsenceIntakeAssistant
{
    private const TYPES = ['sick', 'child_sick', 'vacation', 'other'];

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly AiUsageContext $usageContext,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * @return array{startsOn: ?string, endsOn: ?string, type: string, availabilityPercent: int, clarify: ?string}
     */
    public function parse(string $text, Workspace $workspace): array
    {
        $this->usageContext->set('absence_intake', $workspace);

        $today = new \DateTimeImmutable('today');
        $system = <<<PROMPT
        You parse a staff member's absence note into a structured absence.
        Today is {$today->format('Y-m-d')} ({$today->format('l')}). Resolve relative
        dates ("heute", "morgen", "bis Freitag", "nächste Woche") against today.

        Respond as a JSON object:
        - "startsOn": first absent day, "YYYY-MM-DD" (or null if unclear).
        - "endsOn": last absent day inclusive, "YYYY-MM-DD" (single day → same as startsOn; null if unclear).
        - "type": one of "sick", "child_sick" (own child is ill / "Kind krank"),
          "vacation", "other".
        - "availabilityPercent": 0–100, how much the person can still work despite
          the absence. 0 = fully away (default). Use e.g. 50 for "halbtags krank" /
          "kann noch halbtags arbeiten". Only set > 0 when the note clearly says so.
        - "clarify": a SHORT question in the note's language if the range/type is
          genuinely ambiguous; otherwise null. Ask at most one thing.
        PROMPT;

        $raw = $this->llm->completeJson($system, mb_substr(trim($text), 0, 500), 400);

        $start = $this->cleanDate($raw['startsOn'] ?? null);
        $end = $this->cleanDate($raw['endsOn'] ?? null) ?? $start;
        $type = \in_array($raw['type'] ?? null, self::TYPES, true) ? $raw['type'] : 'sick';
        $availabilityPercent = \is_numeric($raw['availabilityPercent'] ?? null)
            ? max(0, min(100, (int) $raw['availabilityPercent']))
            : 0;
        $clarify = \is_string($raw['clarify'] ?? null) && trim($raw['clarify']) !== ''
            ? trim(mb_substr($raw['clarify'], 0, 300))
            : null;

        // An end before start is nonsense → force a clarification.
        if ($start !== null && $end !== null && $end < $start) {
            $clarify ??= 'Bitte den Zeitraum präzisieren (Enddatum liegt vor dem Start).';
        }

        return ['startsOn' => $start, 'endsOn' => $end, 'type' => $type, 'availabilityPercent' => $availabilityPercent, 'clarify' => $clarify];
    }

    private function cleanDate(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $d instanceof \DateTimeImmutable ? $d->format('Y-m-d') : null;
    }
}
