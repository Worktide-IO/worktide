<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\ResearchMission;
use App\Service\ExternalSearch\ExternalSearchResult;
use App\Service\Llm\LlmProviderInterface;

/**
 * Turns raw external-search hits into scored, structured lead candidates for a
 * {@see ResearchMission}. The LLM reads the mission brief + the hits and returns
 * clean company/person leads with a 0–100 fit score. Suggestions only — the run
 * handler dedupes and persists {@see \App\Entity\Lead} rows. Mirrors the
 * validate-and-cap style of {@see UpgradeOutreachAssistant}.
 */
final class ResearchAssistant
{
    private const MAX_LEADS = 50;
    private const MAX_RESULTS_IN_PROMPT = 60;

    public function __construct(
        private readonly LlmProviderInterface $llm,
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
     * @param ExternalSearchResult[] $results
     *
     * @return array{leads: list<array<string, mixed>>, reasoning: ?string}
     */
    public function extractLeads(ResearchMission $mission, array $results): array
    {
        if ($results === []) {
            return ['leads' => [], 'reasoning' => null];
        }

        $raw = $this->llm->completeJson($this->systemPrompt(), $this->buildContext($mission, $results));

        $leads = [];
        foreach ($this->asList($raw['leads'] ?? null) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue; // never invent nameless leads
            }
            $leads[] = [
                'name' => mb_substr($name, 0, 255),
                'isCompany' => (bool) ($item['isCompany'] ?? true),
                'email' => $this->str($item['email'] ?? null, 255),
                'website' => $this->str($item['website'] ?? null, 255),
                'role' => $this->str($item['role'] ?? null, 160),
                'industry' => $this->str($item['industry'] ?? null, 120),
                'region' => $this->str($item['region'] ?? null, 120),
                'fitScore' => $this->clampScore($item['fitScore'] ?? null),
                'scoreReason' => $this->str($item['scoreReason'] ?? null, 1000),
                'sourceUrl' => $this->str($item['sourceUrl'] ?? null, 1024),
            ];
            if (\count($leads) >= self::MAX_LEADS) {
                break;
            }
        }

        return ['leads' => $leads, 'reasoning' => $this->str($raw['reasoning'] ?? null, 2000)];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are a B2B lead-research assistant. Given a research brief and a list of
            web/company search results, select the results that are genuine candidate
            organizations or people matching the brief, and return them as leads.
            Do NOT invent data — only use what the results support; leave a field null if unknown.
            Score each lead's fit to the brief from 0 (poor) to 100 (excellent) and give a
            one-sentence scoreReason. Reply with STRICT JSON:
            {"leads":[{"name":str,"isCompany":bool,"email":str|null,"website":str|null,
            "role":str|null,"industry":str|null,"region":str|null,"fitScore":int,
            "scoreReason":str,"sourceUrl":str|null}],"reasoning":str}
            `sourceUrl` must be the url of the search result the lead came from.
            PROMPT;
    }

    /**
     * @param ExternalSearchResult[] $results
     */
    private function buildContext(ResearchMission $mission, array $results): string
    {
        $brief = $mission->getBrief();
        $briefText = $brief !== null ? json_encode($brief, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : null;

        $lines = ['# Brief', 'Prompt: ' . $mission->getPrompt()];
        if ($briefText !== null && $briefText !== false) {
            $lines[] = 'Structured: ' . $briefText;
        }
        $lines[] = '';
        $lines[] = '# Search results';
        foreach (\array_slice($results, 0, self::MAX_RESULTS_IN_PROMPT) as $i => $r) {
            $lines[] = sprintf(
                '%d. %s | %s | %s',
                $i + 1,
                mb_substr($r->title, 0, 160),
                $r->url ?? '-',
                mb_substr((string) ($r->snippet ?? ''), 0, 300),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<mixed>
     */
    private function asList(mixed $v): array
    {
        return \is_array($v) ? array_values($v) : [];
    }

    private function str(mixed $v, int $max): ?string
    {
        if (!\is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function clampScore(mixed $v): ?int
    {
        if (!is_numeric($v)) {
            return null;
        }

        return max(0, min(100, (int) $v));
    }
}
