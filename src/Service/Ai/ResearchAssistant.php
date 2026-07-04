<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Enum\ResearchObjective;
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

        // Extraction can emit up to MAX_LEADS structured leads; the default
        // 2048-token budget truncates that JSON (→ parse failure) once many
        // results come in. Give it real headroom.
        $raw = $this->llm->completeJson($this->systemPrompt(), $this->buildContext($mission, $results), 8192);

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

    /**
     * Intake step: decide whether the brief is specific enough to start a search
     * and, if not, ask a few clarifying questions. Called on mission creation and
     * again after every user answer, reading the whole dialog so far.
     *
     * @param list<array{role: string, content: string}> $dialog chronological turns
     *
     * @return array{ready: bool, objective: ?ResearchObjective, message: string, questions: list<array{key: string, question: string, options: list<string>}>, brief: array<string, mixed>}
     */
    public function clarify(ResearchMission $mission, array $dialog): array
    {
        $raw = $this->llm->completeJson($this->clarifySystemPrompt(), $this->clarifyContext($mission, $dialog));

        $questions = [];
        foreach ($this->asList($raw['questions'] ?? null) as $q) {
            if (!\is_array($q)) {
                continue;
            }
            $text = $this->str($q['question'] ?? null, 300);
            if ($text === null) {
                continue;
            }
            $options = [];
            foreach ($this->asList($q['options'] ?? null) as $opt) {
                $o = $this->str($opt, 120);
                if ($o !== null) {
                    $options[] = $o;
                }
            }
            $questions[] = [
                'key' => $this->str($q['key'] ?? null, 60) ?? 'q' . (\count($questions) + 1),
                'question' => $text,
                'options' => \array_slice($options, 0, 6),
            ];
            if (\count($questions) >= 5) {
                break;
            }
        }

        // "ready" means enough is known to search. No questions left ⇒ ready.
        $ready = (bool) ($raw['ready'] ?? false) || $questions === [];
        if ($ready) {
            $questions = [];
        }

        $objective = \is_string($raw['objective'] ?? null) ? ResearchObjective::tryFrom($raw['objective']) : null;
        $message = $this->str($raw['message'] ?? null, 2000)
            ?? ($ready ? 'Der Auftrag ist klar genug — ich kann mit der Suche starten.' : 'Zur Präzisierung habe ich ein paar Rückfragen.');

        return [
            'ready' => $ready,
            'objective' => $objective,
            'message' => $message,
            'questions' => $questions,
            'brief' => $this->normalizeBrief($raw['brief'] ?? null),
        ];
    }

    /**
     * Proactive step: given a snapshot of the agency's business, propose concrete
     * research missions worth running now. Suggestions only — each is surfaced as
     * a pending {@see \App\Entity\AIRecommendation} a human can accept.
     *
     * @return list<array{prompt: string, objective: string, targetCount: ?int, rationale: ?string, brief: array<string, mixed>}>
     */
    public function suggestMissions(string $businessContext): array
    {
        $raw = $this->llm->completeJson($this->suggestSystemPrompt(), $businessContext);

        $out = [];
        foreach ($this->asList($raw['suggestions'] ?? null) as $s) {
            if (!\is_array($s)) {
                continue;
            }
            $prompt = $this->str($s['prompt'] ?? null, 1000);
            if ($prompt === null) {
                continue; // a mission needs an instruction
            }
            $objective = \is_string($s['objective'] ?? null)
                ? (ResearchObjective::tryFrom($s['objective']) ?? ResearchObjective::General)
                : ResearchObjective::General;
            $out[] = [
                'prompt' => $prompt,
                'objective' => $objective->value,
                'targetCount' => $this->clampInt($s['targetCount'] ?? null),
                'rationale' => $this->str($s['rationale'] ?? null, 500),
                'brief' => $this->normalizeBrief($s['brief'] ?? null),
            ];
            if (\count($out) >= 3) {
                break;
            }
        }

        return $out;
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

    private function clampInt(mixed $v): ?int
    {
        if (!is_numeric($v)) {
            return null;
        }

        return max(0, (int) $v);
    }

    /**
     * Normalize an LLM-produced brief into the shape the run handler consumes.
     *
     * @return array<string, mixed>
     */
    private function normalizeBrief(mixed $v): array
    {
        if (!\is_array($v)) {
            return [];
        }
        $criteria = [];
        foreach ($this->asList($v['criteria'] ?? null) as $c) {
            $s = $this->str($c, 200);
            if ($s !== null) {
                $criteria[] = $s;
            }
        }

        return array_filter([
            'query' => $this->str($v['query'] ?? null, 500),
            'targetCount' => $this->clampInt($v['targetCount'] ?? null),
            'region' => $this->str($v['region'] ?? null, 120),
            'industry' => $this->str($v['industry'] ?? null, 120),
            'tech' => $this->str($v['tech'] ?? null, 120),
            'segment' => $this->str($v['segment'] ?? null, 160),
            'limit' => $this->clampInt($v['limit'] ?? null),
            'criteria' => \array_slice($criteria, 0, 12),
        ], static fn (mixed $x): bool => $x !== null && $x !== []);
    }

    private function clarifySystemPrompt(): string
    {
        return <<<'PROMPT'
            You are a B2B research-mission intake assistant. Read the employee's instruction and
            the dialog so far, and decide whether the task is specific enough to start an external
            lead/partner search. If key parameters are missing (target segment, region, industry,
            tech/CMS, count, or what makes a good fit), ask up to 4 SHORT clarifying questions,
            each with 2–5 quick-answer options when sensible. When it is specific enough, set
            ready=true, ask nothing more, and produce a normalized brief. Infer the objective.
            Answer in the user's language (default German). Reply with STRICT JSON:
            {"ready":bool,
             "objective":"lead_generation|partner_search|market_research|content_distribution|general",
             "message":str,
             "questions":[{"key":str,"question":str,"options":[str]}],
             "brief":{"query":str,"targetCount":int|null,"region":str|null,"industry":str|null,
                      "tech":str|null,"segment":str|null,"criteria":[str]}}
            `query` is the search phrase the agent should run. Never invent facts about the user's business.
            PROMPT;
    }

    /**
     * @param list<array{role: string, content: string}> $dialog
     */
    private function clarifyContext(ResearchMission $mission, array $dialog): string
    {
        $lines = ['# Instruction', $mission->getPrompt(), ''];
        $brief = $mission->getBrief();
        if ($brief !== null && $brief !== []) {
            $encoded = json_encode($brief, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $lines[] = '# Current brief';
                $lines[] = $encoded;
                $lines[] = '';
            }
        }
        if ($dialog !== []) {
            $lines[] = '# Dialog so far';
            foreach ($dialog as $turn) {
                $role = ($turn['role'] ?? '') === 'agent' ? 'Agent' : 'User';
                $lines[] = $role . ': ' . mb_substr((string) ($turn['content'] ?? ''), 0, 1000);
            }
        }

        return mb_substr(implode("\n", $lines), 0, 6000);
    }

    private function suggestSystemPrompt(): string
    {
        return <<<'PROMPT'
            You are a growth strategist for a B2B software agency. Given a snapshot of the agency's
            business (its products, customer base and industries), propose up to 3 concrete,
            high-value research missions its acquisition agent could run NOW — e.g. find prospects
            for a specific product, or find partner/key-account candidates in a promising industry.
            Each suggestion must be immediately runnable. Write in German. Reply with STRICT JSON:
            {"suggestions":[{"prompt":str,
              "objective":"lead_generation|partner_search|market_research|content_distribution|general",
              "targetCount":int|null,"rationale":str,
              "brief":{"query":str,"targetCount":int|null,"region":str|null,"industry":str|null,
                       "tech":str|null,"segment":str|null,"criteria":[str]}}]}
            `prompt` is a ready-to-run instruction; `query` is the concrete search phrase.
            Base every suggestion only on the snapshot given — do not invent products or customers.
            PROMPT;
    }
}
