<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomFieldValue;
use App\Entity\Enum\CustomFieldTarget;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\PublicForm;
use App\Entity\PublicFormSubmission;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\PublicFormRepository;
use App\Repository\TaskStatusRepository;
use App\Service\Form\FormLogicEvaluator;
use App\Service\Form\FormSchemaNormalizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns an accepted {@see PublicForm} submission into a {@see Task} plus mapped
 * {@see CustomFieldValue}s and an audit {@see PublicFormSubmission} row.
 *
 * Pure of HTTP concerns — the honeypot, rate-limit, and submission-limit checks
 * live in {@see \App\Controller\Api\PublicFormController}. This service only
 * validates the posted values against the form schema and persists the result.
 *
 * ## Tally-like engine (schema v2)
 *
 * Structure comes from the normalised document ({@see FormSchemaNormalizer}) so
 * v1 (flat) and v2 (pages/blocks) forms share one code path. On submit the
 * server is authoritative:
 *  - branching is re-evaluated here ({@see FormLogicEvaluator}); only *active*
 *    (visible, reachable, non-hidden) fields are validated/required — a field
 *    the logic hid can never be smuggled in, and its value is dropped;
 *  - `calc` fields are computed server-side (client values ignored) and are
 *    available to `mapsTo` and recorded in the audit payload;
 *  - hidden/prefill fields take their value from {@see FormPrefillResolver}
 *    (passed in as $prefill), never from the request body.
 *
 * Validation failures raise {@see PublicFormValidationException} (per-field
 * messages). Task creation mirrors {@see \App\Controller\Api\ImportController}:
 * status falls back to the workspace default, the identifier is minted from the
 * project key, and `createdByUser` stays null (an unauthenticated path, exactly
 * as {@see \App\Entity\Trait\AuditableTrait} documents).
 */
final class PublicFormSubmissionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly CustomFieldDefinitionRepository $customFields,
        private readonly FormSchemaNormalizer $normalizer,
        private readonly FormLogicEvaluator $logic,
        private readonly PublicFormRepository $forms,
    ) {}

    /**
     * @param array<string, mixed> $values  Raw posted values, keyed by field key.
     * @param array<string, mixed> $prefill  Authoritative values for hidden/prefill
     *                                        fields, resolved from the portal context.
     *
     * @throws PublicFormValidationException field-level validation failed
     * @throws PublicFormSubmissionClosedException the submission limit was reached
     */
    public function submit(PublicForm $form, array $values, ?string $ip = null, ?string $userAgent = null, array $prefill = []): PublicFormSubmission
    {
        $doc = $this->normalizer->normalize($form);
        $blocks = $this->normalizer->inputBlocks($doc);

        // Branching is evaluated on the merged raw answers: client-supplied
        // values for visible fields plus server-resolved prefill for hidden ones.
        $rawForLogic = $values;
        foreach ($blocks as $block) {
            if (($block['hidden'] ?? false) === true) {
                $key = (string) $block['key'];
                $rawForLogic[$key] = $prefill[$key] ?? null;
            }
        }
        $evaluation = $this->logic->evaluate($doc, $rawForLogic);
        $active = array_fill_keys($evaluation['activeKeys'], true);

        /** @var array<string, mixed> $coerced field key => validated/coerced value */
        $coerced = [];
        /** @var array<string, string> $errors */
        $errors = [];

        foreach ($blocks as $block) {
            $key = (string) ($block['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $type = (string) ($block['type'] ?? 'text');
            $label = (string) ($block['label'] ?? $key);
            $options = array_map('strval', (array) ($block['options'] ?? []));
            $hidden = ($block['hidden'] ?? false) === true;

            if ($hidden) {
                // Prefill field: value is server-authoritative, never required.
                $raw = $prefill[$key] ?? null;
                if ($raw !== null && $raw !== '') {
                    try {
                        $coerced[$key] = $this->coerce($type, $raw, $options, $block);
                    } catch (\InvalidArgumentException) {
                        // A bad prefill value is a config bug, not a user error — drop it.
                    }
                }
                continue;
            }

            // A field the branching logic deactivated is ignored outright — not
            // validated, not required, its client value discarded.
            if (!isset($active[$key])) {
                continue;
            }

            $required = (bool) ($block['required'] ?? false);
            $raw = $values[$key] ?? null;

            if ($this->isBlank($raw)) {
                if ($required) {
                    $errors[$key] = sprintf('"%s" is required.', $label);
                }
                continue;
            }

            try {
                $coerced[$key] = $this->coerce($type, $raw, $options, $block);
            } catch (\InvalidArgumentException $e) {
                $errors[$key] = sprintf('"%s": %s', $label, $e->getMessage());
            }
        }

        if ($errors !== []) {
            throw new PublicFormValidationException($errors);
        }

        // Computed fields: server-side, from the coerced answers, never trusted
        // from the client. Made available for mapsTo and the audit payload.
        $calc = $this->logic->evaluate($doc, $coerced)['calc'];
        foreach ($calc as $k => $v) {
            $coerced[$k] = $v;
        }

        $mapsToPairs = $this->collectMapsTo($blocks, $doc['calc'] ?? []);

        $task = $this->buildTask($form, $coerced, $mapsToPairs);
        $this->em->persist($task);

        $this->applyCustomFields($form, $task, $coerced, $mapsToPairs);

        $submission = (new PublicFormSubmission())
            ->setForm($form)
            ->setWorkspace($form->getWorkspace())
            ->setPayload($this->stringifyPayload($coerced))
            ->setCreatedTask($task)
            ->setRemoteIp($ip !== null ? mb_substr($ip, 0, 45) : null)
            ->setUserAgent($userAgent !== null ? mb_substr($userAgent, 0, 255) : null);
        $this->em->persist($submission);

        // Atomically claim a submission slot (a single conditional UPDATE that
        // bumps the counter iff the form is under its limit). Race-safe — no
        // read-then-write TOCTOU — and, unlike an ORM increment, it does NOT
        // bump the optimistic-lock version, so concurrent submits no longer
        // collide into an uncaught OptimisticLockException (500).
        if (!$this->forms->tryClaimSubmissionSlot($form)) {
            throw new PublicFormSubmissionClosedException();
        }

        $this->em->flush();

        return $submission;
    }

    /**
     * Flatten input blocks and calc rules to their `mapsTo` routing, in order.
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $calcRules
     *
     * @return list<array{key: string, mapsTo: string}>
     */
    private function collectMapsTo(array $blocks, array $calcRules): array
    {
        $pairs = [];
        foreach ($blocks as $block) {
            $mapsTo = $block['mapsTo'] ?? null;
            $key = (string) ($block['key'] ?? '');
            if ($mapsTo !== null && $mapsTo !== '' && $key !== '') {
                $pairs[] = ['key' => $key, 'mapsTo' => (string) $mapsTo];
            }
        }
        foreach ($calcRules as $rule) {
            $mapsTo = $rule['mapsTo'] ?? null;
            $key = (string) ($rule['key'] ?? '');
            if ($mapsTo !== null && $mapsTo !== '' && $key !== '') {
                $pairs[] = ['key' => $key, 'mapsTo' => (string) $mapsTo];
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $coerced
     * @param list<array{key: string, mapsTo: string}> $mapsToPairs
     */
    private function buildTask(PublicForm $form, array $coerced, array $mapsToPairs): Task
    {
        $title = null;
        $description = null;
        $priority = $form->getDefaultPriority();

        foreach ($mapsToPairs as $pair) {
            $key = $pair['key'];
            if (!\array_key_exists($key, $coerced)) {
                continue;
            }
            $value = $coerced[$key];
            switch ($pair['mapsTo']) {
                case 'title':
                    $title = $this->scalarString($value);
                    break;
                case 'description':
                    $description = $this->scalarString($value);
                    break;
                case 'priority':
                    $priority = TaskPriority::tryFrom((string) $value) ?? $priority;
                    break;
            }
        }

        $project = $form->getProject();
        $task = (new Task())
            ->setWorkspace($form->getWorkspace())
            ->setProject($project)
            ->setTitle($title !== null && $title !== '' ? $title : $form->getTitle())
            ->setDescription($description)
            ->setStatus($this->resolveStatus($form))
            ->setPriority($priority ?? TaskPriority::Normal)
            ->setCreatedVia(TaskCreatedVia::Form)
            ->setIdentifier($project->getKey() . '-' . dechex(random_int(0x1000, 0xFFFF)));

        if ($form->getDefaultTracker() !== null) {
            // null leaves TaskTrackerDefaultsListener to apply the workspace default.
            $task->setTracker($form->getDefaultTracker());
        }

        return $task;
    }

    private function resolveStatus(PublicForm $form): TaskStatus
    {
        if ($form->getDefaultStatus() !== null) {
            return $form->getDefaultStatus();
        }

        $workspace = $form->getWorkspace();
        $default = $this->taskStatuses->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
        if ($default !== null) {
            return $default;
        }

        $statuses = $this->taskStatuses->findBy(['workspace' => $workspace], ['position' => 'ASC'], 1);
        $first = $statuses[0] ?? null;
        if ($first === null) {
            throw new \RuntimeException('Workspace has no task statuses; cannot create a task from a public form.');
        }

        return $first;
    }

    /**
     * @param array<string, mixed> $coerced
     * @param list<array{key: string, mapsTo: string}> $mapsToPairs
     */
    private function applyCustomFields(PublicForm $form, Task $task, array $coerced, array $mapsToPairs): void
    {
        foreach ($mapsToPairs as $pair) {
            $mapsTo = $pair['mapsTo'];
            $key = $pair['key'];
            if (!str_starts_with($mapsTo, 'cf:') || !\array_key_exists($key, $coerced)) {
                continue;
            }
            $cfKey = substr($mapsTo, 3);
            $definition = $this->customFields->findOneBy([
                'workspace' => $form->getWorkspace(),
                'target' => CustomFieldTarget::Task,
                'key' => $cfKey,
            ]);
            if ($definition === null) {
                // Unknown custom-field key — skip gracefully rather than 500.
                continue;
            }

            $value = (new CustomFieldValue())
                ->setWorkspace($form->getWorkspace())
                ->setDefinition($definition)
                ->setTarget(CustomFieldTarget::Task)
                ->setTargetId($task->getId())
                ->setValue($coerced[$key]);
            $this->em->persist($value);
        }
    }

    /** An empty submission for a field: null, "", or []. */
    private function isBlank(mixed $raw): bool
    {
        return $raw === null || $raw === '' || $raw === [];
    }

    private function scalarString(mixed $value): string
    {
        if (\is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }

    /**
     * Coerce + validate a single value by declared field type. Native task
     * targets are still stored as the coerced scalar; `priority` validity is
     * enforced in {@see buildTask} via TaskPriority::tryFrom.
     *
     * @param list<string> $options
     * @param array<string, mixed> $block  full normalised block (for min/max/rows)
     *
     * @throws \InvalidArgumentException on a type mismatch
     */
    private function coerce(string $type, mixed $raw, array $options, array $block): mixed
    {
        return match ($type) {
            'number' => $this->coerceNumber($raw),
            'boolean' => filter_var($raw, \FILTER_VALIDATE_BOOLEAN),
            'select' => $this->coerceSelect($raw, $options),
            'email' => $this->coerceFilter($raw, \FILTER_VALIDATE_EMAIL, 'must be a valid email address.'),
            'url' => $this->coerceFilter($raw, \FILTER_VALIDATE_URL, 'must be a valid URL.'),
            'date' => $this->coerceDate($raw),
            'multi_select' => $this->coerceMultiSelect($raw, $options),
            'rating', 'scale' => $this->coerceRange($raw, $block, $type),
            'matrix' => $this->coerceMatrix($raw, $block),
            'file' => $this->coerceFile($raw),
            default => (string) $raw, // text, long_text, unknown → plain string
        };
    }

    private function coerceNumber(mixed $raw): int|float
    {
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException('must be a number.');
        }
        $raw += 0; // numeric string → int|float

        return $raw;
    }

    /** @param list<string> $options */
    private function coerceSelect(mixed $raw, array $options): string
    {
        $value = (string) $raw;
        if ($options !== [] && !\in_array($value, $options, true)) {
            throw new \InvalidArgumentException('is not one of the allowed options.');
        }

        return $value;
    }

    /**
     * @param list<string> $options
     *
     * @return list<string>
     */
    private function coerceMultiSelect(mixed $raw, array $options): array
    {
        $values = \is_array($raw) ? array_values(array_map('strval', $raw)) : [(string) $raw];
        if ($options !== []) {
            foreach ($values as $value) {
                if (!\in_array($value, $options, true)) {
                    throw new \InvalidArgumentException('contains a value that is not an allowed option.');
                }
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function coerceRange(mixed $raw, array $block, string $type): int
    {
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException('must be a number.');
        }
        $value = (int) $raw;
        $min = isset($block['min']) ? (int) $block['min'] : ($type === 'rating' ? 1 : 0);
        $max = isset($block['max']) && (int) $block['max'] > 0 ? (int) $block['max'] : ($type === 'rating' ? 5 : 10);
        if ($value < $min || $value > $max) {
            throw new \InvalidArgumentException(sprintf('must be between %d and %d.', $min, $max));
        }

        return $value;
    }

    /**
     * A matrix answer is a map of row label → chosen option; each option must be
     * one of the block's options (when constrained).
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, string>
     */
    private function coerceMatrix(mixed $raw, array $block): array
    {
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('must be a set of selections.');
        }
        $options = array_map('strval', (array) ($block['options'] ?? []));
        $rows = array_map('strval', (array) ($block['rows'] ?? []));
        $out = [];
        foreach ($raw as $row => $choice) {
            $row = (string) $row;
            if ($rows !== [] && !\in_array($row, $rows, true)) {
                continue; // ignore unknown rows
            }
            $choice = (string) $choice;
            if ($options !== [] && !\in_array($choice, $options, true)) {
                throw new \InvalidArgumentException('has a selection that is not an allowed option.');
            }
            $out[$row] = $choice;
        }

        return $out;
    }

    /**
     * A file answer is a reference to an already-uploaded object (id or URL),
     * not the bytes. The portal upload endpoint returns the reference; here we
     * only sanity-check it is a non-empty string.
     */
    private function coerceFile(mixed $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            throw new \InvalidArgumentException('must be an uploaded file reference.');
        }

        return $value;
    }

    private function coerceFilter(mixed $raw, int $filter, string $message): string
    {
        $value = (string) $raw;
        if (filter_var($value, $filter) === false) {
            throw new \InvalidArgumentException($message);
        }

        return $value;
    }

    private function coerceDate(mixed $raw): string
    {
        try {
            return (new \DateTimeImmutable((string) $raw))->format('Y-m-d');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('must be a valid date.');
        }
    }

    /**
     * Flatten the payload to JSON-safe scalars/strings for the audit row.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function stringifyPayload(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            if (str_starts_with((string) $k, '_')) {
                continue; // drop reserved keys like the honeypot
            }
            $out[(string) $k] = is_scalar($v) || $v === null ? $v : json_encode($v);
        }

        return $out;
    }
}
