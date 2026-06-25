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
use App\Repository\TaskStatusRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns an accepted {@see PublicForm} submission into a {@see Task} plus mapped
 * {@see CustomFieldValue}s and an audit {@see PublicFormSubmission} row.
 *
 * Pure of HTTP concerns — the honeypot, rate-limit, and submission-limit checks
 * live in {@see \App\Controller\Api\PublicFormController}. This service only
 * validates the posted values against the form schema and persists the result.
 *
 * Validation failures raise {@see PublicFormValidationException} (per-field
 * messages). Task creation mirrors {@see \App\Controller\Api\ImportController}:
 * status falls back to the workspace default, the identifier is minted from the
 * project key, and `createdByUser` stays null (an unauthenticated path, exactly
 * as {@see \App\Entity\Trait\AuditableTrait} documents).
 */
final class PublicFormSubmissionService
{
    private const NATIVE_TARGETS = ['title', 'description', 'priority'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly CustomFieldDefinitionRepository $customFields,
    ) {}

    /**
     * @param array<string, mixed> $values Raw posted values, keyed by field key.
     *
     * @throws PublicFormValidationException
     */
    public function submit(PublicForm $form, array $values, ?string $ip = null, ?string $userAgent = null): PublicFormSubmission
    {
        /** @var array<string, mixed> $coerced field key => validated/coerced value */
        $coerced = [];
        /** @var array<string, string> $errors */
        $errors = [];

        foreach ($form->getFields() as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $label = (string) ($field['label'] ?? $key);
            $type = (string) ($field['type'] ?? 'text');
            $required = (bool) ($field['required'] ?? false);
            $options = array_map('strval', (array) ($field['options'] ?? []));
            $raw = $values[$key] ?? null;

            if ($raw === null || $raw === '') {
                if ($required) {
                    $errors[$key] = sprintf('"%s" is required.', $label);
                }
                continue;
            }

            try {
                $coerced[$key] = $this->coerce($type, $raw, $options);
            } catch (\InvalidArgumentException $e) {
                $errors[$key] = sprintf('"%s": %s', $label, $e->getMessage());
            }
        }

        if ($errors !== []) {
            throw new PublicFormValidationException($errors);
        }

        $task = $this->buildTask($form, $coerced);
        $this->em->persist($task);

        $this->applyCustomFields($form, $task, $coerced);

        $submission = (new PublicFormSubmission())
            ->setForm($form)
            ->setWorkspace($form->getWorkspace())
            ->setPayload($this->stringifyPayload($values))
            ->setCreatedTask($task)
            ->setRemoteIp($ip !== null ? mb_substr($ip, 0, 45) : null)
            ->setUserAgent($userAgent !== null ? mb_substr($userAgent, 0, 255) : null);
        $this->em->persist($submission);

        $form->incrementSubmissionCount();

        $this->em->flush();

        return $submission;
    }

    /**
     * @param array<string, mixed> $coerced
     */
    private function buildTask(PublicForm $form, array $coerced): Task
    {
        $title = null;
        $description = null;
        $priority = $form->getDefaultPriority();

        foreach ($form->getFields() as $field) {
            $mapsTo = $field['mapsTo'] ?? null;
            $key = (string) ($field['key'] ?? '');
            if ($mapsTo === null || $key === '' || !\array_key_exists($key, $coerced)) {
                continue;
            }
            $value = $coerced[$key];
            switch ($mapsTo) {
                case 'title':
                    $title = (string) $value;
                    break;
                case 'description':
                    $description = (string) $value;
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
     */
    private function applyCustomFields(PublicForm $form, Task $task, array $coerced): void
    {
        foreach ($form->getFields() as $field) {
            $mapsTo = (string) ($field['mapsTo'] ?? '');
            $key = (string) ($field['key'] ?? '');
            if (!str_starts_with($mapsTo, 'cf:') || $key === '' || !\array_key_exists($key, $coerced)) {
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

    /**
     * Coerce + validate a single value by declared field type. Native task
     * targets are still stored as the coerced scalar; `priority` validity is
     * enforced in {@see buildTask} via TaskPriority::tryFrom.
     *
     * @param list<string> $options
     *
     * @throws \InvalidArgumentException on a type mismatch
     */
    private function coerce(string $type, mixed $raw, array $options): mixed
    {
        return match ($type) {
            'number' => $this->coerceNumber($raw),
            'boolean' => filter_var($raw, \FILTER_VALIDATE_BOOLEAN),
            'select' => $this->coerceSelect($raw, $options),
            'email' => $this->coerceFilter($raw, \FILTER_VALIDATE_EMAIL, 'must be a valid email address.'),
            'url' => $this->coerceFilter($raw, \FILTER_VALIDATE_URL, 'must be a valid URL.'),
            'date' => $this->coerceDate($raw),
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
     * Flatten the raw payload to JSON-safe scalars/strings for the audit row.
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
