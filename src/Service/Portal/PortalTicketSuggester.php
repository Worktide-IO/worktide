<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Service\Llm\LlmProviderInterface;

/**
 * Turns a customer's free-text ticket description into a suggested title,
 * priority and (optionally) project — the "KI-Strukturierung" from wireframe
 * screen 2. Human-in-the-loop: this only proposes; the customer reviews the
 * pre-filled form and still submits it themselves.
 *
 * Synchronous, prompt-driven JSON (models {@see \App\Service\Ai\TicketTriageAssistant}).
 * Every field the model returns is validated against known values — an invented
 * priority falls back to Normal, an unknown projectId is dropped.
 */
final class PortalTicketSuggester
{
    private const MAX_TITLE = 120;

    public function __construct(private readonly LlmProviderInterface $llm) {}

    /** Whether the LLM is configured — lets the caller return a clean 503. */
    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    /**
     * @param list<Project> $projects the customer's allowed projects
     *
     * @return array{title: string, priority: string, projectId: ?string}
     */
    public function suggest(string $description, array $projects): array
    {
        $allowedIds = [];
        $projectLines = [];
        foreach ($projects as $p) {
            $pid = $p->getId()?->toRfc4122();
            if ($pid !== null) {
                $allowedIds[$pid] = true;
                $projectLines[] = sprintf('- %s · %s', $pid, $p->getName());
            }
        }

        $system = 'Du bist der Support-Assistent einer Digitalagentur. Aus der Freitext-Beschreibung '
            . 'eines Kunden erzeugst du einen prägnanten deutschen Ticket-Titel (max. 8 Wörter, keine '
            . 'Anführungszeichen), schätzt die Priorität und ordnest – wenn möglich – ein Projekt zu. '
            . 'Prioritäten: "low" (Kleinigkeit), "normal" (Standard), "high" (wichtig/blockiert Arbeit), '
            . '"urgent" (Ausfall/dringend). Antworte als JSON mit den Schlüsseln title (string), '
            . 'priority (low|normal|high|urgent) und projectId (string oder null).';

        $user = "Beschreibung des Kunden:\n" . $description . "\n\n"
            . ($projectLines === []
                ? 'Es sind keine Projekte verfügbar; setze projectId immer auf null.'
                : "Verfügbare Projekte (projectId · Name):\n" . implode("\n", $projectLines)
                    . "\nWähle projectId NUR aus dieser Liste; wenn unklar, null.");

        $out = $this->llm->completeJson($system, $user);

        return [
            'title' => $this->cleanTitle($out['title'] ?? null, $description),
            'priority' => $this->cleanPriority($out['priority'] ?? null),
            'projectId' => $this->cleanProjectId($out['projectId'] ?? null, $allowedIds),
        ];
    }

    private function cleanTitle(mixed $raw, string $fallbackFrom): string
    {
        $title = \is_string($raw) ? trim($raw, " \t\n\r\0\x0B\"'") : '';
        if ($title === '') {
            // Fall back to the first line of the description.
            $title = trim(strtok($fallbackFrom, "\n") ?: 'Neues Ticket');
        }

        return mb_substr($title, 0, self::MAX_TITLE);
    }

    private function cleanPriority(mixed $raw): string
    {
        $priority = \is_string($raw) ? TaskPriority::tryFrom(strtolower(trim($raw))) : null;

        return ($priority ?? TaskPriority::Normal)->value;
    }

    /** @param array<string, true> $allowedIds */
    private function cleanProjectId(mixed $raw, array $allowedIds): ?string
    {
        return \is_string($raw) && isset($allowedIds[$raw]) ? $raw : null;
    }
}
