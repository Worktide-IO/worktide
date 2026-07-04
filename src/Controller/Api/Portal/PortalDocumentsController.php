<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Document;
use App\Entity\Enum\DocumentWorkflowState;
use App\Entity\Project;
use App\Repository\DocumentRepository;
use App\Service\Portal\PortalAccessResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Customer-portal "Wissen / Dateien" — the knowledge documents the agency has
 * published for the customer.
 *
 * A document is portal-visible only when it is Published, not private, not
 * archived, NOT isHiddenForConnectUsers, and lives in one of the customer's
 * external projects (see {@see PortalAccessResolver::allowedProjects()}). The
 * body is returned as-is (markdown) for the detail view. Gated by `documents`.
 */
final class PortalDocumentsController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly DocumentRepository $documents,
    ) {}

    #[Route(
        path: '/v1/portal/documents',
        name: 'api_portal_documents_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('documents');

        $documents = $this->documents->findPublishedForPortalProjects($this->portal->allowedProjects());

        return new JsonResponse([
            'documents' => array_map($this->documentDto(...), $documents),
        ]);
    }

    #[Route(
        path: '/v1/portal/documents/{id}',
        name: 'api_portal_documents_show',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('documents');

        $document = $this->findDocumentOr404($id);

        return new JsonResponse($this->documentDto($document) + [
            'body' => $document->getBody(),
            'bodyFormat' => $document->getBodyFormat()->value,
        ]);
    }

    /**
     * Load a single portal-visible document, or 404. Mirrors the list filter
     * (published / not hidden / not private / not archived / in an allowed
     * project) — never removes a gate.
     */
    private function findDocumentOr404(string $id): Document
    {
        $doc = $this->documents->find(Uuid::fromString($id));
        if (
            !$doc instanceof Document
            || $doc->getDeletedAt() !== null
            || $doc->getWorkflowState() !== DocumentWorkflowState::Published
            || $doc->isHiddenForConnectUsers()
            || $doc->isPrivate()
            || $doc->isArchived()
            || !$this->isAllowedProject($doc->getProject())
        ) {
            throw new NotFoundHttpException('Document not found.');
        }
        return $doc;
    }

    private function isAllowedProject(?Project $project): bool
    {
        if ($project === null) {
            return false;
        }
        foreach ($this->portal->allowedProjects() as $allowed) {
            if ($allowed->getId()?->equals($project->getId()) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentDto(Document $document): array
    {
        return [
            'id' => $document->getId()?->toRfc4122(),
            'name' => $document->getName(),
            'emoji' => $document->getEmoji(),
            'spaceName' => $document->getSpace()?->getName(),
            'projectName' => $document->getProject()?->getName(),
            'updatedAt' => $document->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'publishedAt' => $document->getPublishedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
