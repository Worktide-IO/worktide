<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Customer;
use App\Entity\Document;
use App\Entity\Project;
use App\Entity\Task;
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
 * GET /v1/links/resolve?url=<encoded>
 *
 * Resolves a Worktide URL or human identifier into a card-ready
 * preview payload — so the SPA editor can render a link as a chip
 * with title + type badge + status instead of raw text.
 *
 * Accepted inputs (`url` query param, URL-encoded):
 *   - Full URL pointing at the SPA (https://.../projects/<uuid>,
 *     .../tasks/<uuid>, .../documents/<uuid>, .../customers/<uuid>)
 *   - IRI pointing at the API   (/v1/projects/<uuid>, …)
 *   - Bare UUID                 (treated as ambiguous — only resolves
 *                                via path context)
 *   - Task identifier           (WORK-123 / WORK-3fd7)
 *
 * Returns 200 with { type, id, url, title, subtitle, status?, color? }
 * for hits, 404 for unknowns. Every match is filtered through the
 * VIEW voter so a leaked URL never reveals data the caller couldn't
 * have read otherwise.
 */
final class LinkResolverController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/links/resolve',
        name: 'api_links_resolve',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $raw = (string) ($request->query->get('url') ?? '');
        if ($raw === '') {
            throw new BadRequestHttpException('url query parameter required.');
        }

        [$type, $id] = $this->classify($raw);
        if ($type === null) {
            throw new NotFoundHttpException('Could not resolve a Worktide entity from this URL.');
        }

        $payload = match ($type) {
            'project'  => $this->resolveProject($id),
            'task'     => $this->resolveTask($id),
            'document' => $this->resolveDocument($id),
            'customer' => $this->resolveCustomer($id),
            default    => null,
        };
        if ($payload === null) {
            throw new NotFoundHttpException('Entity not found or you are not allowed to view it.');
        }

        return new JsonResponse($payload);
    }

    /**
     * @return array{0: ?string, 1: string}  resource type + id-or-identifier
     */
    private function classify(string $raw): array
    {
        // Strip protocol+host so the same path-classifier handles both
        // .ddev.site URLs and bare IRIs.
        $path = parse_url($raw, \PHP_URL_PATH) ?? $raw;
        $path = '/' . ltrim($path, '/');

        // Worktide URL or IRI shapes: /v1/<resource>/<uuid>, /projects/<uuid>, etc.
        $patterns = [
            'project'  => '#/(?:v1/)?projects/([0-9a-f-]{36})#i',
            'task'     => '#/(?:v1/)?tasks/([0-9a-f-]{36})#i',
            'document' => '#/(?:v1/)?documents/([0-9a-f-]{36})#i',
            'customer' => '#/(?:v1/)?customers/([0-9a-f-]{36})#i',
        ];
        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $path, $m) === 1) {
                return [$type, $m[1]];
            }
        }
        // Task identifier shape (WORK-123, WIKI-2, etc.)
        if (preg_match('/^[A-Z][A-Z0-9]{1,15}-[A-Z0-9]+$/', $raw)) {
            return ['task', $raw];
        }
        return [null, ''];
    }

    private function resolveProject(string $idOrIdentifier): ?array
    {
        try {
            $project = $this->em->find(Project::class, Uuid::fromString($idOrIdentifier));
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($project === null || !$this->security->isGranted(WorktidePermission::VIEW, $project)) {
            return null;
        }
        return [
            'type' => 'project',
            'id' => $project->getId()?->toRfc4122(),
            'url' => '/v1/projects/' . $project->getId()?->toRfc4122(),
            'title' => $project->getName(),
            'subtitle' => $project->getKey(),
            'color' => $project->getColor(),
            'status' => $project->getStatus()->getName(),
            'isArchived' => $project->isArchived(),
        ];
    }

    private function resolveTask(string $idOrIdentifier): ?array
    {
        $task = null;
        if (str_contains($idOrIdentifier, '-') && preg_match('/^[A-Z][A-Z0-9]{1,15}-[A-Z0-9]+$/', $idOrIdentifier)) {
            // Resolved by human identifier (WORK-123)
            $task = $this->em->getRepository(Task::class)->findOneBy(['identifier' => $idOrIdentifier]);
        } else {
            try {
                $task = $this->em->find(Task::class, Uuid::fromString($idOrIdentifier));
            } catch (\InvalidArgumentException) {
                return null;
            }
        }
        if ($task === null || !$this->security->isGranted(WorktidePermission::VIEW, $task)) {
            return null;
        }
        $status = $task->getStatus();
        return [
            'type' => 'task',
            'id' => $task->getId()?->toRfc4122(),
            'url' => '/v1/tasks/' . $task->getId()?->toRfc4122(),
            'title' => $task->getTitle(),
            'subtitle' => $task->getIdentifier(),
            'status' => $status->getName(),
            'statusCompleted' => $status->isCompleted(),
            'priority' => $task->getPriority()->value,
            'isPrio' => $task->isPrio(),
            'projectId' => $task->getProject()?->getId()?->toRfc4122(),
            'closedOn' => $task->getClosedOn()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resolveDocument(string $id): ?array
    {
        try {
            $doc = $this->em->find(Document::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($doc === null || !$this->security->isGranted(WorktidePermission::VIEW, $doc)) {
            return null;
        }
        return [
            'type' => 'document',
            'id' => $doc->getId()?->toRfc4122(),
            'url' => '/v1/documents/' . $doc->getId()?->toRfc4122(),
            'title' => $doc->getName() ?: 'Untitled',
            'subtitle' => null,
            'emoji' => $doc->getEmoji(),
        ];
    }

    private function resolveCustomer(string $id): ?array
    {
        try {
            $customer = $this->em->find(Customer::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($customer === null || !$this->security->isGranted(WorktidePermission::VIEW, $customer)) {
            return null;
        }
        return [
            'type' => 'customer',
            'id' => $customer->getId()?->toRfc4122(),
            'url' => '/v1/customers/' . $customer->getId()?->toRfc4122(),
            'title' => $customer->getName(),
            'subtitle' => $customer->getLegalName() !== $customer->getName() ? $customer->getLegalName() : null,
            'status' => $customer->getStatus()->value,
        ];
    }
}
