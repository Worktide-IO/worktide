<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\User;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * POST /v1/documents/{id}/restore
 * Body: { "revision": "<uuid|iri>" }
 *
 * Copies the revision's name + body back onto the live document. That
 * mutation goes through the standard update path so it creates its own
 * "current state was X before restore" revision (snapshot of where we
 * were just before reverting).
 *
 * Authorisation: the EDIT voter on the live Document — same gate as
 * any other PATCH on it.
 */
final class DocumentRestoreController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/documents/{id}/restore',
        name: 'api_documents_restore',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $document = $this->em->find(Document::class, Uuid::fromString($id));
        if ($document === null) {
            throw new NotFoundHttpException('Document not found.');
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $document)) {
            throw new AccessDeniedHttpException('No edit permission for this document.');
        }

        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            throw new BadRequestHttpException('Body must be JSON object.');
        }
        $ref = $body['revision'] ?? null;
        if (!is_string($ref) || $ref === '') {
            throw new BadRequestHttpException('"revision" required.');
        }
        // Accept IRI or plain UUID.
        $revisionId = str_contains($ref, '/') ? basename($ref) : $ref;
        try {
            $revision = $this->em->find(DocumentRevision::class, Uuid::fromString($revisionId));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid revision UUID.');
        }
        if ($revision === null) {
            throw new NotFoundHttpException('Revision not found.');
        }
        if ($revision->getDocument()->getId()?->toRfc4122() !== $document->getId()?->toRfc4122()) {
            throw new BadRequestHttpException('Revision belongs to a different document.');
        }

        // Apply the revision content back onto the live document. The
        // preUpdate listener will then snapshot the *current* state as
        // its own new revision before this overwrite — so the user can
        // always undo a restore.
        $document->setName($revision->getName() ?: 'Untitled');
        $document->setBody($revision->getBody());
        $document->setBodyFormat($revision->getBodyFormat());

        $this->em->flush();

        return new JsonResponse([
            '@id' => '/v1/documents/' . $document->getId()?->toRfc4122(),
            'id' => $document->getId()?->toRfc4122(),
            'name' => $document->getName(),
            'restoredFrom' => '/v1/document_revisions/' . $revision->getId()?->toRfc4122(),
        ]);
    }
}
