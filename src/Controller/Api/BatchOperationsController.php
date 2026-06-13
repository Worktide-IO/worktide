<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Generic batch operations on Project / Task / TimeEntry, matching awork's
 * `*-batch-by-operation` endpoints.
 *
 * Body:
 *   {
 *     "ids": ["uuid", "uuid", ...],
 *     "operation": "set" | "archive" | "unarchive" | "delete",
 *     "fields":   { ... }       // for "set"
 *   }
 *
 * Per-id security is enforced — items the caller can't EDIT are silently
 * skipped and counted in the `skipped` response field.
 *
 * Endpoints:
 *   POST /v1/projects/batch
 *   POST /v1/tasks/batch
 *   POST /v1/time-entries/batch
 */
final class BatchOperationsController
{
    private const MAX_ITEMS = 200;

    private const SETTABLE_FIELDS = [
        Project::class => ['color', 'isArchived', 'isPrivate', 'isBillableByDefault'],
        Task::class => ['priority', 'isPrio', 'isHiddenForConnectUsers'],
        TimeEntry::class => ['isBillable', 'isBilled', 'note'],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/v1/projects/batch', name: 'api_projects_batch', host: 'api.worktide.ddev.site', methods: ['POST'])]
    public function projects(Request $request): JsonResponse
    {
        return $this->handle($request, Project::class);
    }

    #[Route('/v1/tasks/batch', name: 'api_tasks_batch', host: 'api.worktide.ddev.site', methods: ['POST'])]
    public function tasks(Request $request): JsonResponse
    {
        return $this->handle($request, Task::class);
    }

    #[Route('/v1/time-entries/batch', name: 'api_time_entries_batch', host: 'api.worktide.ddev.site', methods: ['POST'])]
    public function timeEntries(Request $request): JsonResponse
    {
        return $this->handle($request, TimeEntry::class);
    }

    /**
     * @param class-string $class
     */
    private function handle(Request $request, string $class): JsonResponse
    {
        $body = $this->body($request);
        $ids = $body['ids'] ?? null;
        $operation = $body['operation'] ?? null;
        $fields = $body['fields'] ?? [];

        if (!\is_array($ids) || $ids === []) {
            throw new BadRequestHttpException('Field ids[] required, non-empty.');
        }
        if (\count($ids) > self::MAX_ITEMS) {
            throw new BadRequestHttpException('Batch size capped at ' . self::MAX_ITEMS . '.');
        }
        if (!\is_string($operation) || !\in_array($operation, ['set', 'archive', 'unarchive', 'delete'], true)) {
            throw new BadRequestHttpException('operation must be one of: set, archive, unarchive, delete.');
        }
        if ($operation === 'set' && (!\is_array($fields) || $fields === [])) {
            throw new BadRequestHttpException('operation=set requires non-empty fields object.');
        }

        $repo = $this->em->getRepository($class);

        $processed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($ids as $raw) {
            if (!\is_string($raw)) {
                $errors[] = ['id' => $raw, 'reason' => 'not_a_string'];
                continue;
            }
            try {
                $uuid = Uuid::fromString($raw);
            } catch (\InvalidArgumentException) {
                $errors[] = ['id' => $raw, 'reason' => 'not_a_uuid'];
                continue;
            }
            $entity = $repo->find($uuid);
            if ($entity === null) {
                $errors[] = ['id' => $raw, 'reason' => 'not_found'];
                continue;
            }
            $needed = $operation === 'delete' ? WorktidePermission::DELETE : WorktidePermission::EDIT;
            if (!$this->security->isGranted($needed, $entity)) {
                $skipped++;
                continue;
            }

            match ($operation) {
                'set' => $this->applySet($entity, $class, $fields),
                'archive' => $this->applyArchive($entity, true),
                'unarchive' => $this->applyArchive($entity, false),
                'delete' => $this->applyDelete($entity),
            };
            $processed++;
        }

        if ($processed > 0) {
            $this->em->flush();
        }

        return new JsonResponse([
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
            'operation' => $operation,
        ]);
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $fields
     */
    private function applySet(object $entity, string $class, array $fields): void
    {
        $allowed = self::SETTABLE_FIELDS[$class] ?? [];
        foreach ($fields as $name => $value) {
            if (!\in_array($name, $allowed, true)) {
                continue;
            }
            $setter = 'set' . ucfirst($name);
            if (\str_starts_with($name, 'is')) {
                $setter = 'set' . ucfirst($name);
            }
            if (\method_exists($entity, $setter)) {
                $entity->{$setter}($value);
            }
        }
    }

    private function applyArchive(object $entity, bool $archived): void
    {
        if (\method_exists($entity, 'setIsArchived')) {
            $entity->setIsArchived($archived);
        }
    }

    private function applyDelete(object $entity): void
    {
        if (\method_exists($entity, 'softDelete')) {
            $entity->softDelete();
        } else {
            $this->em->remove($entity);
        }
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON: ' . $e->getMessage(), $e);
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
