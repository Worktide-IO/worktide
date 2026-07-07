<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Service\Search\SearchHit;
use App\Service\Search\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Global full-text search across the workspace's content (mail, tasks, CRM,
 * projects, documents, comments):
 *
 *   GET /v1/search?q=<term>&types=task,conversation&limit=20
 *
 * Workspace-scoped and membership-checked (the backing provider is also
 * workspace-filtered). Backend is chosen by SEARCH_PROVIDER (MySQL default,
 * Meilisearch drop-in) — the response shape is identical either way.
 */
final class SearchController
{
    private const int DEFAULT_LIMIT = 20;
    private const int MAX_LIMIT = 50;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly SearchProviderInterface $provider,
    ) {}

    #[Route(
        path: '/v1/search',
        name: 'api_search',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $workspace = $this->resolveWorkspace($request, $user);
        $workspaceId = $workspace?->getId();
        if ($workspace === null || $workspaceId === null || !$this->isMember($user, $workspace)) {
            throw new AccessDeniedHttpException('No accessible workspace context.');
        }

        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return new JsonResponse(['hits' => []]);
        }

        $types = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->query->get('types', ''))),
            static fn (string $t): bool => $t !== '',
        ));
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT)));

        $hits = $this->provider->search($query, $workspaceId, $types, $limit);

        return new JsonResponse(['hits' => array_map(static fn (SearchHit $h): array => $h->toArray(), $hits)]);
    }

    private function isMember(User $user, Workspace $workspace): bool
    {
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        return $this->em->getRepository(WorkspaceMember::class)
            ->findOneBy(['user' => $user, 'workspace' => $workspace]) !== null;
    }

    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $hdr = $request->headers->get('X-Workspace-Id');
        if (\is_string($hdr) && $hdr !== '') {
            try {
                return $this->em->find(Workspace::class, Uuid::fromString($hdr));
            } catch (\InvalidArgumentException) {
                return null;
            }
        }
        $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);

        return $membership?->getWorkspace();
    }
}
