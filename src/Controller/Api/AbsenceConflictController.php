<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Service\Absence\AbsenceConflictResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Backs the "which appointments / tickets can you no longer keep?" review shown
 * after a limited-availability absence is recorded through the regular absence
 * form. Unlike the conversational AbsenceIntakeController this is not "me"-only:
 * a manager entering an absence for a colleague passes that `user`, which is
 * membership-checked against the workspace before anything is surfaced.
 *
 *   POST /v1/absence-conflicts          { user, startsOn, endsOn }
 *        → { bookings: [...], customers: [...] }
 *   POST /v1/absence-conflicts/resolve  { user, bookingIds: [...], taskIds: [...] }
 *        → { cancelled: [...], drafted: [...] }
 */
final class AbsenceConflictController
{
    use ResolvesWorkspaceMembership;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly AbsenceConflictResolver $resolver,
    ) {}

    #[Route(path: '/v1/absence-conflicts', name: 'api_absence_conflicts', methods: ['POST'])]
    public function conflicts(Request $request): JsonResponse
    {
        [, $workspace, $target, $body] = $this->context($request);

        $start = $this->dateField($body, 'startsOn');
        $end = $this->dateField($body, 'endsOn');
        if ($start === null || $end === null) {
            throw new BadRequestHttpException('startsOn/endsOn required.');
        }

        return new JsonResponse($this->resolver->findConflicts($target, $workspace, $start, $end));
    }

    #[Route(path: '/v1/absence-conflicts/resolve', name: 'api_absence_conflicts_resolve', methods: ['POST'])]
    public function resolve(Request $request): JsonResponse
    {
        [, $workspace, $target, $body] = $this->context($request);

        $bookingIds = \is_array($body['bookingIds'] ?? null) ? array_values($body['bookingIds']) : [];
        $taskIds = \is_array($body['taskIds'] ?? null) ? array_values($body['taskIds']) : [];
        if ($bookingIds === [] && $taskIds === []) {
            throw new BadRequestHttpException('bookingIds or taskIds required.');
        }

        return new JsonResponse($this->resolver->resolve($target, $workspace, $bookingIds, $taskIds));
    }

    /**
     * @return array{0: User, 1: Workspace, 2: User, 3: array<string, mixed>}
     */
    private function context(Request $request): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }

        $body = json_decode($request->getContent(), true);
        $body = \is_array($body) ? $body : [];

        $target = $this->resolveMember($body['user'] ?? null, $workspace) ?? $user;

        return [$user, $workspace, $target, $body];
    }

    /** Resolve a workspace-member user from an IRI or raw UUID; null → caller. */
    private function resolveMember(mixed $raw, Workspace $workspace): ?User
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }
        $parts = explode('/', $raw);
        $id = (string) end($parts);
        if (!Uuid::isValid($id)) {
            return null;
        }
        $target = $this->em->find(User::class, Uuid::fromString($id));
        if ($target === null) {
            return null;
        }
        $member = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['workspace' => $workspace, 'user' => $target]);

        return $member !== null ? $target : null;
    }

    private function dateField(array $body, string $key): ?\DateTimeImmutable
    {
        $value = $body[$key] ?? null;
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
