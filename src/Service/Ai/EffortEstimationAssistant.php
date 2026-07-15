<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Tag;
use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Repository\TimeEntryRepository;
use App\Service\Llm\AiUsageContext;
use App\Service\Llm\LlmProviderInterface;

/**
 * Suggests an effort estimate ({@see Task::$estimatedMinutes}) for a task by
 * showing the model how long *similar completed tasks* actually took (summed
 * TimeEntry minutes) plus their prior estimate, if any. Suggestions only —
 * applied via {@see RecommendationApplier} on accept, never here.
 *
 * "Similar" is a cheap local ranking (same tracker, shared tags) over the
 * workspace's recent completed tasks; only tasks with real logged time feed the
 * prompt, so the model calibrates against ground truth rather than guessing.
 * Mirrors {@see TicketTriageAssistant}: the model's number is validated to a
 * sane positive integer so an accepted suggestion always applies cleanly.
 */
final class EffortEstimationAssistant
{
    /** How many similar tasks (with logged time) to show the model. */
    private const MAX_HISTORY = 12;
    private const MAX_TEXT = 4000;
    /** Clamp guardrail: reject absurd numbers (> ~3 person-months). */
    private const MAX_MINUTES = 100_000;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly TaskRepository $tasks,
        private readonly TimeEntryRepository $timeEntries,
        private readonly AiUsageContext $usageContext,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    /** The model that produced a suggestion — stored on the recommendation for provenance. */
    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * @return array{suggestion: array{estimatedMinutes: ?int, sampleSize: int}, reasoning: ?string}
     */
    public function estimate(Task $task): array
    {
        $history = $this->buildHistory($task);

        $this->usageContext->set('estimate', $task->getWorkspace());
        $raw = $this->llm->completeJson($this->systemPrompt(), $this->buildContext($task, $history));

        return [
            'suggestion' => [
                'estimatedMinutes' => $this->cleanMinutes($raw['estimatedMinutes'] ?? null),
                'sampleSize' => \count($history),
            ],
            'reasoning' => $this->cleanReasoning($raw['reasoning'] ?? null),
        ];
    }

    /**
     * Similar completed tasks that actually have logged time, ranked by a cheap
     * similarity score (same tracker + shared tags), newest as tie-breaker.
     *
     * @return list<array{title: string, tracker: ?string, tags: list<string>, estimatedMinutes: ?int, actualMinutes: int}>
     */
    private function buildHistory(Task $task): array
    {
        $id = $task->getId();
        if ($id === null) {
            return [];
        }

        $targetTracker = $task->getTracker()?->getName();
        $targetTags = $this->tagNames($task);

        $scored = [];
        foreach ($this->tasks->findEstimationCandidates($task->getWorkspace(), $id) as $candidate) {
            $candidateId = $candidate->getId();
            if ($candidateId === null) {
                continue;
            }
            $actual = $this->timeEntries->sumMinutesForTask($candidateId);
            if ($actual <= 0) {
                continue; // no ground truth → nothing to learn from
            }

            $candidateTags = $this->tagNames($candidate);
            $sharedTags = array_values(array_intersect($targetTags, $candidateTags));
            $score = ($targetTracker !== null && $candidate->getTracker()?->getName() === $targetTracker ? 2 : 0)
                + \count($sharedTags);

            $scored[] = [
                'score' => $score,
                'row' => [
                    'title' => $candidate->getTitle(),
                    'tracker' => $candidate->getTracker()?->getName(),
                    'tags' => $candidateTags,
                    'estimatedMinutes' => $candidate->getEstimatedMinutes(),
                    'actualMinutes' => $actual,
                ],
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(
            static fn (array $s): array => $s['row'],
            \array_slice($scored, 0, self::MAX_HISTORY),
        );
    }

    /** @return list<string> */
    private function tagNames(Task $task): array
    {
        return array_values(array_map(static fn (Tag $t): string => $t->getName(), $task->getTags()->toArray()));
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You are a project-management effort-estimation assistant. Given a task and a
        table of SIMILAR COMPLETED tasks with their actual logged time (and prior
        estimate, if any), estimate how long the new task will take.

        Base your number on the actual times of the most similar past tasks; weight
        same-tracker / shared-tag tasks more. Do not invent details. If the history is
        empty or unrelated, give your best rough estimate and say so in the reasoning.

        Respond as a JSON object with these keys:
        - "estimatedMinutes": an integer number of minutes (whole minutes, > 0).
        - "reasoning": one or two short sentences, in the task's own language, explaining
          which past tasks drove the number.
        PROMPT;
    }

    /**
     * @param list<array{title: string, tracker: ?string, tags: list<string>, estimatedMinutes: ?int, actualMinutes: int}> $history
     */
    private function buildContext(Task $task, array $history): string
    {
        $parts = [
            'NEW TASK',
            'Title: ' . $task->getTitle(),
            'Description: ' . (trim((string) $task->getDescription()) !== '' ? $task->getDescription() : '(none)'),
            'Tracker: ' . ($task->getTracker()?->getName() ?? '(none)'),
            'Tags: ' . ($this->tagNames($task) === [] ? '(none)' : implode(', ', $this->tagNames($task))),
            '',
            'SIMILAR COMPLETED TASKS (actual = real logged time):',
        ];

        if ($history === []) {
            $parts[] = '(no comparable history with logged time)';
        } else {
            foreach ($history as $h) {
                $tags = $h['tags'] === [] ? '' : ' [' . implode(', ', $h['tags']) . ']';
                $est = $h['estimatedMinutes'] !== null ? sprintf(', estimated %dm', $h['estimatedMinutes']) : '';
                $parts[] = sprintf(
                    '- "%s" (%s)%s: actual %dm%s',
                    $h['title'],
                    $h['tracker'] ?? 'no tracker',
                    $tags,
                    $h['actualMinutes'],
                    $est,
                );
            }
        }

        return mb_substr(implode("\n", $parts), 0, self::MAX_TEXT);
    }

    private function cleanMinutes(mixed $value): ?int
    {
        if (\is_int($value)) {
            $n = $value;
        } elseif (\is_float($value) || (\is_string($value) && is_numeric($value))) {
            $n = (int) round((float) $value);
        } else {
            return null;
        }

        if ($n <= 0 || $n > self::MAX_MINUTES) {
            return null;
        }

        return $n;
    }

    private function cleanReasoning(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s === '' ? null : mb_substr($s, 0, 1000);
    }
}
