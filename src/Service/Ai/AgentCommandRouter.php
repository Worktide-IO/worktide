<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Workspace;
use App\Service\Llm\AiUsageContext;
use App\Service\Llm\LlmProviderInterface;

/**
 * Routes a staff member's free-text dashboard command into one intent + extracted
 * fields, so the controller can propose a concrete action to confirm. Deliberately
 * a small, fixed intent set mapped to existing capabilities; names ("Kunde XY",
 * "Produkt Z") are only *extracted* here and resolved to ids server-side (keeps
 * the prompt small + resolution deterministic). Feature "command" for usage/budget.
 */
final class AgentCommandRouter
{
    private const INTENTS = ['absence', 'create_ticket', 'promote_product', 'clarify'];
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
     * @return array{
     *   intent: string, clarify: ?string,
     *   startsOn: ?string, endsOn: ?string, absenceType: string, availabilityPercent: int,
     *   title: ?string, description: ?string, customerName: ?string, projectName: ?string,
     *   productName: ?string
     * }
     */
    public function route(string $text, Workspace $workspace): array
    {
        $this->usageContext->set('command', $workspace);
        $today = new \DateTimeImmutable('today');

        $system = <<<PROMPT
        You route a staff member's free-text command into ONE intent and extract its
        fields. Today is {$today->format('Y-m-d')} ({$today->format('l')}).

        Intents:
        - "absence": the person reports being sick/away. Extract startsOn/endsOn
          ("YYYY-MM-DD", resolve relative dates), absenceType
          ("sick"|"child_sick" (own child ill)|"vacation"|"other"), and
          availabilityPercent (0–100, how much they can still work; 0 = fully away,
          e.g. 50 for "halbtags krank"; default 0).
        - "create_ticket": a task/feature/request to track. Extract a concise "title",
          a "description" (the details/request), and any named "customerName" and/or
          "projectName" mentioned.
        - "promote_product": marketing/advertising a product. Extract "productName".
        - "clarify": intent unclear or a required detail is missing.

        Respond as a JSON object with keys: intent (one of absence|create_ticket|
        promote_product|clarify), clarify (a short question in the user's language when
        intent is clarify OR a needed field is ambiguous, else null), startsOn, endsOn,
        absenceType, availabilityPercent, title, description, customerName, projectName,
        productName (use null for anything not applicable). Extract names verbatim; do not invent ids.
        PROMPT;

        $raw = $this->llm->completeJson($system, mb_substr(trim($text), 0, 1000), 700);

        $intent = \in_array($raw['intent'] ?? null, self::INTENTS, true) ? $raw['intent'] : 'clarify';

        return [
            'intent' => $intent,
            'clarify' => $this->str($raw['clarify'] ?? null, 300),
            'startsOn' => $this->date($raw['startsOn'] ?? null),
            'endsOn' => $this->date($raw['endsOn'] ?? null) ?? $this->date($raw['startsOn'] ?? null),
            'absenceType' => \in_array($raw['absenceType'] ?? null, self::TYPES, true) ? $raw['absenceType'] : 'sick',
            'availabilityPercent' => \is_numeric($raw['availabilityPercent'] ?? null) ? max(0, min(100, (int) $raw['availabilityPercent'])) : 0,
            'title' => $this->str($raw['title'] ?? null, 200),
            'description' => $this->str($raw['description'] ?? null, 4000),
            'customerName' => $this->str($raw['customerName'] ?? null, 120),
            'projectName' => $this->str($raw['projectName'] ?? null, 120),
            'productName' => $this->str($raw['productName'] ?? null, 120),
        ];
    }

    private function str(mixed $v, int $max): ?string
    {
        if (!\is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    private function date(mixed $v): ?string
    {
        if (!\is_string($v)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));

        return $d instanceof \DateTimeImmutable ? $d->format('Y-m-d') : null;
    }
}
