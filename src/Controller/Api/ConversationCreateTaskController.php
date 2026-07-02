<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\Project;
use App\Security\Voter\WorktidePermission;
use App\Service\Inbound\ConversationTaskConverter;
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
 * 1-click "create task from conversation" (Phase C Schicht 4):
 *
 *   POST /v1/conversations/{id}/create-task   { "project": "<iri|uuid>", "title"?: "..." }
 *
 * Access gated on VIEW of the conversation's workspace; the task is created in
 * the chosen project and remembers its origin. Conversion lives in
 * {@see ConversationTaskConverter}.
 */
final class ConversationCreateTaskController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ConversationTaskConverter $converter,
    ) {}

    #[Route(
        path: '/v1/conversations/{id}/create-task',
        name: 'api_conversation_create_task',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $conversation = $this->em->find(Conversation::class, Uuid::fromString($id));
        if ($conversation === null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $conversation)) {
            throw new AccessDeniedHttpException();
        }

        $data = json_decode($request->getContent(), true);
        $data = \is_array($data) ? $data : [];
        $project = $this->resolveProject($data['project'] ?? null);
        $title = isset($data['title']) && \is_string($data['title']) ? $data['title'] : null;

        try {
            $task = $this->converter->convert($conversation, $project, $title);
        } catch (\DomainException $e) {
            throw new ConflictHttpException($e->getMessage());
        }

        return new JsonResponse([
            'taskId' => $task->getId()?->toRfc4122(),
            'taskIdentifier' => $task->getIdentifier(),
        ], Response::HTTP_CREATED);
    }

    private function resolveProject(mixed $ref): Project
    {
        if (!\is_string($ref) || $ref === '') {
            throw new BadRequestHttpException('Missing "project".');
        }
        $candidate = str_contains($ref, '/') ? substr((string) strrchr($ref, '/'), 1) : $ref;
        if (!Uuid::isValid($candidate)) {
            throw new BadRequestHttpException('"project" must be a UUID or IRI.');
        }
        $project = $this->em->find(Project::class, Uuid::fromString($candidate));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found.');
        }

        return $project;
    }
}
