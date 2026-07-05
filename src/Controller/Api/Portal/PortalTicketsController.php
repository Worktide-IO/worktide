<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Comment;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\File;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\FileRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskStatusRepository;
use App\Service\Llm\LlmException;
use App\Service\Portal\PortalAccessResolver;
use App\Service\Portal\PortalSlaCalculator;
use App\Service\Portal\PortalTicketSuggester;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Customer-portal ticket endpoints — a "ticket" is a {@see Task} in one of the
 * customer's external projects (see {@see PortalAccessResolver}).
 *
 * All reads/writes go through the resolver, which enforces the portal identity
 * chain and the `isHiddenForConnectUsers` gate. Responses are curated DTOs —
 * never the raw entity — so internal fields (assignees, priority score,
 * trackers, …) never leak to the customer.
 */
final class PortalTicketsController
{
    /** low/normal/high/urgent → customer-facing German label. */
    private const PRIORITY_LABELS = [
        'low' => 'Niedrig',
        'normal' => 'Mittel',
        'high' => 'Hoch',
        'urgent' => 'Dringend',
    ];

    /** Memoized per-request effective SLA policy (customer over workspace). */
    private ?array $slaPolicy = null;

    /** task-id (rfc4122) → first agency-reply time, for the SLA response leg. */
    private array $firstReplyByTask = [];

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly TaskRepository $tasks,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly CommentRepository $comments,
        private readonly FileRepository $files,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PortalSlaCalculator $sla,
        private readonly PortalTicketSuggester $suggester,
    ) {}

    #[Route(
        path: '/v1/portal/tickets',
        name: 'api_portal_tickets_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        $tickets = $this->tasks->findVisiblePortalTickets($this->portal->allowedProjects());
        $this->loadFirstReplies($tickets);

        return new JsonResponse([
            'tickets' => array_map($this->ticketDto(...), $tickets),
        ]);
    }

    #[Route(
        path: '/v1/portal/tickets/{id}',
        name: 'api_portal_tickets_show',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function show(string $id): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        $task = $this->portal->findTicketOr404(Uuid::fromString($id));
        $this->loadFirstReplies([$task]);

        $comments = $this->comments->findBy(
            [
                'target' => CommentTarget::Task,
                'targetId' => $task->getId(),
                'isHiddenForConnectUsers' => false,
            ],
            ['createdAt' => 'ASC'],
        );

        return new JsonResponse([
            'ticket' => $this->ticketDto($task) + [
                'description' => $task->getDescription(),
            ],
            'comments' => array_map(static fn (Comment $c): array => [
                'id' => $c->getId()?->toRfc4122(),
                'author' => $c->getAuthor()->getFullName(),
                'content' => $c->getContent(),
                'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ], $comments),
            'attachments' => array_map(static fn (File $f): array => [
                'id' => $f->getId()?->toRfc4122(),
                'name' => $f->getName(),
                'mimeType' => $f->getMimeType(),
                'size' => $f->getSize(),
                'uploadedAt' => $f->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ], $this->files->findVisibleForTask($task)),
        ]);
    }

    #[Route(
        path: '/v1/portal/tickets',
        name: 'api_portal_tickets_create',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function create(Request $request): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        $body = $this->body($request);
        $title = \is_string($body['title'] ?? null) ? trim($body['title']) : '';
        if ($title === '') {
            throw new BadRequestHttpException('title required.');
        }
        $description = \is_string($body['description'] ?? null) ? $body['description'] : null;
        $priority = TaskPriority::tryFrom((string) ($body['priority'] ?? '')) ?? TaskPriority::Normal;

        $project = $this->resolveProject($body['projectId'] ?? null);

        $task = (new Task())
            ->setWorkspace($project->getWorkspace())
            ->setProject($project)
            ->setTitle($title)
            ->setDescription($description)
            ->setStatus($this->defaultStatus($project))
            ->setPriority($priority)
            ->setCreatedVia(TaskCreatedVia::Portal)
            ->setCreatedBy($this->portalUser())
            // Minted like other programmatic task creators (ConversationTaskConverter):
            // project key + short hex suffix, e.g. WORK-3fd7.
            ->setIdentifier($project->getKey() . '-' . dechex(random_int(0x1000, 0xFFFF)));

        $this->em->persist($task);
        $this->em->flush();

        return new JsonResponse($this->ticketDto($task), 201);
    }

    #[Route(
        path: '/v1/portal/tickets/suggest',
        name: 'api_portal_tickets_suggest',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function suggest(Request $request): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        if (!$this->suggester->isAvailable()) {
            throw new ServiceUnavailableHttpException(null, 'KI-Vorschlag ist derzeit nicht verfügbar.');
        }

        $body = $this->body($request);
        $description = \is_string($body['description'] ?? null) ? trim($body['description']) : '';
        if ($description === '') {
            throw new BadRequestHttpException('description required.');
        }

        $projects = $this->portal->allowedProjects();
        try {
            $suggestion = $this->suggester->suggest($description, $projects);
        } catch (LlmException) {
            throw new ServiceUnavailableHttpException(null, 'KI-Vorschlag fehlgeschlagen.');
        }

        $projectName = null;
        foreach ($projects as $project) {
            if ($project->getId()?->toRfc4122() === $suggestion['projectId']) {
                $projectName = $project->getName();
                break;
            }
        }

        return new JsonResponse([
            'title' => $suggestion['title'],
            'priority' => $suggestion['priority'],
            'priorityLabel' => self::PRIORITY_LABELS[$suggestion['priority']] ?? $suggestion['priority'],
            'projectId' => $suggestion['projectId'],
            'projectName' => $projectName,
        ]);
    }

    #[Route(
        path: '/v1/portal/tickets/{id}/comments',
        name: 'api_portal_tickets_comment',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function comment(string $id, Request $request): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        $task = $this->portal->findTicketOr404(Uuid::fromString($id));

        $body = $this->body($request);
        $content = \is_string($body['content'] ?? null) ? trim($body['content']) : '';
        if ($content === '') {
            throw new BadRequestHttpException('content required.');
        }

        $comment = (new Comment())
            ->setWorkspace($task->getWorkspace())
            ->setTarget(CommentTarget::Task)
            ->setTargetId($task->getId())
            ->setAuthor($this->portalUser())
            ->setContent($content);
        // Portal-authored comments are always visible (never hidden from the
        // customer who wrote them).
        $comment->setIsHiddenForConnectUsers(false);

        $this->em->persist($comment);
        $this->em->flush();

        return new JsonResponse([
            'id' => $comment->getId()?->toRfc4122(),
            'author' => $comment->getAuthor()->getFullName(),
            'content' => $comment->getContent(),
            'createdAt' => $comment->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    /**
     * Pick the target project for a new ticket: the given projectId if it is
     * in the allowed set; the sole allowed project if there is exactly one;
     * otherwise 400/409.
     */
    private function resolveProject(mixed $projectId): Project
    {
        $allowed = $this->portal->allowedProjects();
        if ($allowed === []) {
            throw new ConflictHttpException('No portal project available for this customer.');
        }

        if (\is_string($projectId) && $projectId !== '') {
            foreach ($allowed as $project) {
                if ($project->getId()?->toRfc4122() === $projectId) {
                    return $project;
                }
            }
            throw new BadRequestHttpException('projectId is not an allowed portal project.');
        }

        if (\count($allowed) === 1) {
            return $allowed[0];
        }

        throw new BadRequestHttpException('projectId required (multiple portal projects available).');
    }

    private function defaultStatus(Project $project): \App\Entity\TaskStatus
    {
        $workspace = $project->getWorkspace();
        $default = $this->taskStatuses->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
        if ($default !== null) {
            return $default;
        }
        $first = $this->taskStatuses->findBy(['workspace' => $workspace], ['position' => 'ASC'], 1)[0] ?? null;
        if ($first === null) {
            throw new ConflictHttpException('Workspace has no task statuses.');
        }
        return $first;
    }

    private function portalUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketDto(Task $task): array
    {
        $priority = $task->getPriority()->value;

        return [
            'id' => $task->getId()?->toRfc4122(),
            'identifier' => $task->getIdentifier(),
            'title' => $task->getTitle(),
            'statusLabel' => $task->getStatus()->getName(),
            'priority' => $priority,
            'priorityLabel' => self::PRIORITY_LABELS[$priority] ?? $priority,
            'dueOn' => $task->getDueOn()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $task->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $task->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'projectName' => $task->getProject()?->getName(),
            'sla' => $this->sla->describe(
                $task,
                $this->slaPolicy(),
                $this->firstReplyByTask[$task->getId()?->toRfc4122() ?? ''] ?? null,
                new \DateTimeImmutable(),
            ),
        ];
    }

    /**
     * Effective SLA policy = workspace `settings.portal.sla` with the customer's
     * `slaPolicy` overlaid per priority (customer wins). Memoized per request.
     *
     * @return array<string, mixed>
     */
    private function slaPolicy(): array
    {
        if ($this->slaPolicy === null) {
            $portal = $this->portal->workspace()->getSettings()['portal'] ?? [];
            $ws = (\is_array($portal) ? ($portal['sla'] ?? []) : []);
            $ws = \is_array($ws) ? $ws : [];
            $this->slaPolicy = array_replace($ws, $this->portal->customer()->getSlaPolicy());
        }

        return $this->slaPolicy;
    }

    /**
     * Load the first-agency-reply time for a set of tickets (SLA response leg),
     * excluding the customer's own comments. Memoized into $firstReplyByTask.
     *
     * @param list<Task> $tasks
     */
    private function loadFirstReplies(array $tasks): void
    {
        $ids = array_values(array_filter(array_map(static fn (Task $t) => $t->getId(), $tasks)));
        $selfUserId = $this->portal->contact()->getLinkedUser()?->getId()?->toRfc4122();
        $this->firstReplyByTask = $this->comments->firstAgencyReplyByTask($ids, $selfUserId);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be valid JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
