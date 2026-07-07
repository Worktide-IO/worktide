<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Document;
use App\Entity\DomainEventLog;
use App\Entity\Enum\DocumentWorkflowState;
use App\Entity\User;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * State-machine controller for the Document approval workflow.
 *
 * The workflow has three states:
 *   draft     → editable working copy. Default for new documents.
 *   review    → submitted for review. Reviewers can approve or send back.
 *   published → formally approved. Edits send it back to draft.
 *
 * Transitions are *only* legal via these endpoints — the PATCH operation
 * does not accept `workflowState` writes. That keeps the state machine
 * authoritative on the server and lets us emit a domain event for every
 * step.
 *
 *   POST /v1/documents/{id}/submit
 *     body: { reviewers: [<userIri>, …] }   (optional — defaults to keeping current list)
 *     draft → review
 *
 *   POST /v1/documents/{id}/approve
 *     body: { note?: string }
 *     review → published. Caller must be in the reviewers list OR have
 *     workspace MANAGE permission.
 *
 *   POST /v1/documents/{id}/request-changes
 *     body: { note?: string }
 *     review → draft. Same authorisation as approve.
 *
 * Every transition emits a DomainEventLog row in the same flush so
 * Mercure subscribers / a future notification builder see the state
 * change atomically with the document update.
 */
final class DocumentWorkflowController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/documents/{id}/submit',
        name: 'api_documents_submit',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    public function submit(string $id, Request $request): JsonResponse
    {
        [$doc, $actor] = $this->loadDocument($id);

        // The author/contributor edits the doc, so EDIT is the right gate.
        if (!$this->security->isGranted(WorktidePermission::EDIT, $doc)) {
            throw new AccessDeniedHttpException('No edit permission.');
        }
        if ($doc->getWorkflowState() === DocumentWorkflowState::Review) {
            throw new ConflictHttpException('Document is already under review.');
        }

        $body = $this->decodeBody($request);
        if (array_key_exists('reviewers', $body)) {
            if (!is_array($body['reviewers'])) {
                throw new BadRequestHttpException('"reviewers" must be an array of IRIs.');
            }
            $this->replaceReviewers($doc, $body['reviewers']);
        }

        $previous = $doc->getWorkflowState();
        $doc->setWorkflowState(DocumentWorkflowState::Review);
        $doc->setSubmittedAt(new \DateTimeImmutable());
        $doc->setSubmittedBy($actor);

        $this->emit('document.submitted_for_review', $doc, $actor, [
            'from' => $previous->value,
            'reviewers' => array_map(
                fn (User $u) => '/v1/users/' . $u->getId()?->toRfc4122(),
                $doc->getReviewers()->toArray(),
            ),
        ]);

        $this->em->flush();
        return new JsonResponse($this->shape($doc));
    }

    #[Route(
        path: '/v1/documents/{id}/approve',
        name: 'api_documents_approve',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    public function approve(string $id, Request $request): JsonResponse
    {
        [$doc, $actor] = $this->loadDocument($id);
        $this->assertCanReview($doc, $actor);

        if ($doc->getWorkflowState() !== DocumentWorkflowState::Review) {
            throw new ConflictHttpException('Only documents in review can be approved.');
        }

        $body = $this->decodeBody($request);
        $note = is_string($body['note'] ?? null) ? trim($body['note']) : '';

        $doc->setWorkflowState(DocumentWorkflowState::Published);
        $doc->setPublishedAt(new \DateTimeImmutable());
        $doc->setPublishedBy($actor);

        $this->emit('document.approved', $doc, $actor, [
            'note' => $note !== '' ? $note : null,
        ]);

        $this->em->flush();
        return new JsonResponse($this->shape($doc));
    }

    #[Route(
        path: '/v1/documents/{id}/request-changes',
        name: 'api_documents_request_changes',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    public function requestChanges(string $id, Request $request): JsonResponse
    {
        [$doc, $actor] = $this->loadDocument($id);
        $this->assertCanReview($doc, $actor);

        if ($doc->getWorkflowState() !== DocumentWorkflowState::Review) {
            throw new ConflictHttpException('Only documents in review can have changes requested.');
        }

        $body = $this->decodeBody($request);
        $note = is_string($body['note'] ?? null) ? trim($body['note']) : '';
        if ($note === '') {
            throw new BadRequestHttpException('A note explaining what to change is required.');
        }

        $doc->setWorkflowState(DocumentWorkflowState::Draft);

        $this->emit('document.changes_requested', $doc, $actor, [
            'note' => $note,
        ]);

        $this->em->flush();
        return new JsonResponse($this->shape($doc));
    }

    /**
     * @return array{0: Document, 1: User}
     */
    private function loadDocument(string $id): array
    {
        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            throw new AccessDeniedHttpException();
        }
        try {
            $doc = $this->em->find(Document::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        if ($doc === null) {
            throw new NotFoundHttpException('Document not found.');
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $doc)) {
            throw new AccessDeniedHttpException();
        }
        return [$doc, $actor];
    }

    /**
     * Approve / request-changes is open to anyone who is either:
     *   - a listed reviewer on the document, OR
     *   - a user with MANAGE on the document (typically owner/admin).
     *
     * This intentionally lets a workspace admin force-approve a document
     * whose explicit reviewers have left the company. If the doc has no
     * reviewers at all, only MANAGE-holders can act.
     */
    private function assertCanReview(Document $doc, User $actor): void
    {
        $isListed = false;
        foreach ($doc->getReviewers() as $r) {
            if ($r->getId()?->toRfc4122() === $actor->getId()?->toRfc4122()) {
                $isListed = true;
                break;
            }
        }
        if ($isListed) {
            return;
        }
        if ($this->security->isGranted(WorktidePermission::MANAGE, $doc)) {
            return;
        }
        throw new AccessDeniedHttpException('Only assigned reviewers (or document managers) can approve or send back this document.');
    }

    /**
     * @param list<mixed> $iris
     */
    private function replaceReviewers(Document $doc, array $iris): void
    {
        $wanted = [];
        foreach ($iris as $iri) {
            if (!is_string($iri)) {
                throw new BadRequestHttpException('Reviewer entries must be IRI strings.');
            }
            if (preg_match('#^/v1/users/([0-9a-f-]{36})$#', $iri, $m) !== 1) {
                throw new BadRequestHttpException("Malformed user IRI: $iri");
            }
            $user = $this->em->find(User::class, Uuid::fromString($m[1]));
            if ($user === null) {
                throw new BadRequestHttpException("Reviewer not found: $iri");
            }
            $wanted[$user->getId()?->toRfc4122()] = $user;
        }
        // Drop reviewers no longer in the new list.
        foreach ($doc->getReviewers()->toArray() as $existing) {
            $key = $existing->getId()?->toRfc4122();
            if (!isset($wanted[$key])) {
                $doc->removeReviewer($existing);
            }
        }
        // Add new ones.
        foreach ($wanted as $u) {
            $doc->addReviewer($u);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '' || $raw === null) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emit(string $name, Document $doc, User $actor, array $payload): void
    {
        $payload['documentId'] = $doc->getId()?->toRfc4122();
        $payload['documentName'] = $doc->getName();
        $payload['toState'] = $doc->getWorkflowState()->value;
        $event = new DomainEventLog(
            $name,
            'Document',
            $doc->getId(),
            $doc->getWorkspace(),
            $actor,
            $payload,
        );
        $this->em->persist($event);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Document $doc): array
    {
        return [
            '@id' => '/v1/documents/' . $doc->getId()?->toRfc4122(),
            'id' => $doc->getId()?->toRfc4122(),
            'workflowState' => $doc->getWorkflowState()->value,
            'submittedAt' => $doc->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
            'submittedBy' => $doc->getSubmittedBy() !== null
                ? '/v1/users/' . $doc->getSubmittedBy()->getId()?->toRfc4122()
                : null,
            'publishedAt' => $doc->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'publishedBy' => $doc->getPublishedBy() !== null
                ? '/v1/users/' . $doc->getPublishedBy()->getId()?->toRfc4122()
                : null,
            'reviewers' => array_map(
                fn (User $u) => '/v1/users/' . $u->getId()?->toRfc4122(),
                $doc->getReviewers()->toArray(),
            ),
        ];
    }
}
