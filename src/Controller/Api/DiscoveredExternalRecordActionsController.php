<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\DiscoveredExternalRecord;
use App\Entity\Project;
use App\Entity\Task;
use App\Security\Voter\WorktidePermission;
use App\Service\Inbound\DiscoveredRecordImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Operator actions on a {@see DiscoveredExternalRecord} (C.7.6):
 *
 *   POST /v1/discovered_external_records/{id}/import   { "project": <iri|uuid> }
 *   POST /v1/discovered_external_records/{id}/link      { "task": <iri|uuid> }
 *   POST /v1/discovered_external_records/{id}/dismiss
 *
 * All writes go through {@see DiscoveredRecordImporter}; this controller only
 * resolves + authorizes. Access is gated on VIEW of the record's workspace
 * (delegated to {@see \App\Security\Voter\WorkspaceVoter}). A non-Pending record
 * yields 409 (the importer throws), so a double action can't create two tasks.
 */
final class DiscoveredExternalRecordActionsController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly DiscoveredRecordImporter $importer,
    ) {}

    #[Route(
        path: '/v1/discovered_external_records/{id}/import',
        name: 'api_discovered_record_import',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function import(string $id, Request $request): JsonResponse
    {
        $record = $this->record($id);
        $project = $this->resolve(Project::class, $this->ref($request, 'project'));

        try {
            $task = $this->importer->import($record, $project);
        } catch (\DomainException $e) {
            throw new ConflictHttpException($e->getMessage());
        }

        return new JsonResponse([
            'state' => $record->getState()->value,
            'taskId' => $task->getId()?->toRfc4122(),
            'taskIdentifier' => $task->getIdentifier(),
        ], Response::HTTP_CREATED);
    }

    #[Route(
        path: '/v1/discovered_external_records/{id}/link',
        name: 'api_discovered_record_link',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function link(string $id, Request $request): JsonResponse
    {
        $record = $this->record($id);
        $task = $this->resolve(Task::class, $this->ref($request, 'task'));

        try {
            $mapping = $this->importer->link($record, $task);
        } catch (\DomainException $e) {
            throw new ConflictHttpException($e->getMessage());
        }

        return new JsonResponse([
            'state' => $record->getState()->value,
            'taskId' => $task->getId()?->toRfc4122(),
            'mappingId' => $mapping->getId()?->toRfc4122(),
        ]);
    }

    #[Route(
        path: '/v1/discovered_external_records/{id}/dismiss',
        name: 'api_discovered_record_dismiss',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function dismiss(string $id): JsonResponse
    {
        $record = $this->record($id);

        try {
            $this->importer->dismiss($record);
        } catch (\DomainException $e) {
            throw new ConflictHttpException($e->getMessage());
        }

        return new JsonResponse(['state' => $record->getState()->value]);
    }

    private function record(string $id): DiscoveredExternalRecord
    {
        $record = $this->em->find(DiscoveredExternalRecord::class, Uuid::fromString($id));
        if ($record === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $record->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }

        return $record;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function resolve(string $class, string $ref): object
    {
        $entity = $this->em->find($class, Uuid::fromString($ref));
        if ($entity === null) {
            throw new NotFoundHttpException(sprintf('%s not found.', (new \ReflectionClass($class))->getShortName()));
        }

        return $entity;
    }

    /** Pull a UUID reference out of the JSON body, accepting a raw UUID or an IRI. */
    private function ref(Request $request, string $key): string
    {
        $data = json_decode($request->getContent(), true);
        $value = \is_array($data) ? ($data[$key] ?? null) : null;
        if (!\is_string($value) || $value === '') {
            throw new BadRequestHttpException(sprintf('Missing "%s".', $key));
        }

        // Accept an API-Platform IRI ("/v1/projects/<uuid>") or a bare UUID.
        $candidate = str_contains($value, '/') ? substr((string) strrchr($value, '/'), 1) : $value;
        if (!Uuid::isValid($candidate)) {
            throw new BadRequestHttpException(sprintf('"%s" must be a UUID or IRI.', $key));
        }

        return $candidate;
    }
}
