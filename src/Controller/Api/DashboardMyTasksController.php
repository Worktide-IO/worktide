<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Dashboard read-model for the "Meine Aufgaben" widget.
 *
 * The SPA used to fetch the WHOLE tasks collection (+ all projects + all task
 * statuses) with pagination:off and filter/bucket it client-side —
 * O(workspace-size) network for a widget that shows a handful of rows. This
 * endpoint returns only the current user's directly-assigned tasks that are
 * open OR due within the next week, with the project summary + open-flag
 * inlined, so the widget needs a single scoped request. The client still
 * buckets today/week/overdue in its own timezone (the server can't know it).
 *
 *   GET /v1/dashboard/my-tasks
 *     &workspace=<uuid>   (defaults to the caller's first membership; the
 *                          X-Workspace-Id header is honoured too)
 *
 * Response:
 *   {
 *     "tasks": [ { "@id", "id", "identifier", "title", "dueOn"|null,
 *                  "status", "isOpen",
 *                  "project": {"@id","id","name","color"}|null } ],
 *     "capped": bool     // true when the personal queue exceeded the cap
 *   }
 */
final class DashboardMyTasksController
{
    /** A personal work-queue past this is noise for a dashboard widget. */
    private const CAP = 200;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/dashboard/my-tasks', name: 'api_dashboard_my_tasks', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        $workspace = $this->resolveWorkspace($request, $user);
        if ($workspace === null) {
            throw new AccessDeniedHttpException('No workspace context.');
        }

        // Open tasks cover no-due + overdue + open-due-soon; the extra due-window
        // clause covers closed-but-due-this-week that the widget still lists. The
        // window is deliberately wide (−2d/+8d UTC) so no timezone offset can drop
        // an edge task the client's local-day bucketing would show.
        $now = new \DateTimeImmutable('now');
        $winStart = $now->modify('-2 days');
        $winEnd = $now->modify('+8 days');

        // +1 so we can tell "exactly CAP" from "more than CAP" without a count query.
        $tasks = $this->em->createQueryBuilder()
            ->select('t', 's', 'p')
            ->from(Task::class, 't')
            ->join('t.status', 's')
            ->leftJoin('t.project', 'p')
            ->innerJoin('t.assignedPrincipals', 'ap', 'WITH', 'ap.principalType = :ptype AND ap.principalId = :uid')
            ->andWhere('t.workspace = :ws')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('s.isCompleted = false OR (t.dueOn >= :winStart AND t.dueOn < :winEnd)')
            ->setParameter('ptype', AssigneePrincipalType::User)
            ->setParameter('uid', $user->getId(), UuidType::NAME)
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('winStart', $winStart)
            ->setParameter('winEnd', $winEnd)
            ->orderBy('t.dueOn', 'ASC')
            ->setMaxResults(self::CAP + 1)
            ->getQuery()
            ->getResult();

        $capped = \count($tasks) > self::CAP;
        if ($capped) {
            $tasks = \array_slice($tasks, 0, self::CAP);
        }

        return new JsonResponse([
            'tasks' => array_map($this->serialise(...), $tasks),
            'capped' => $capped,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialise(Task $t): array
    {
        $status = $t->getStatus();
        $project = $t->getProject();

        return [
            '@id' => '/v1/tasks/' . $t->getId()->toRfc4122(),
            'id' => $t->getId()->toRfc4122(),
            'identifier' => $t->getIdentifier(),
            'title' => $t->getTitle(),
            'priority' => $t->getPriority()->value,
            'dueOn' => $t->getDueOn()?->format(\DateTimeInterface::ATOM),
            'status' => '/v1/task_statuses/' . $status->getId()->toRfc4122(),
            'isOpen' => !$status->isCompleted(),
            'project' => $project === null ? null : [
                '@id' => '/v1/projects/' . $project->getId()->toRfc4122(),
                'id' => $project->getId()->toRfc4122(),
                'name' => $project->getName(),
                'color' => $project->getColor(),
            ],
        ];
    }

    private function resolveWorkspace(Request $request, User $user): ?Workspace
    {
        $hdr = $request->headers->get('X-Workspace-Id') ?? $request->query->get('workspace');
        if (\is_string($hdr) && $hdr !== '') {
            try {
                $ws = $this->em->find(Workspace::class, Uuid::fromString($hdr));
            } catch (\InvalidArgumentException) {
                return null;
            }
            // Never trust a client-supplied workspace id: only return it when the
            // caller is actually a member (else cross-tenant leak).
            if ($ws === null || $this->em->getRepository(WorkspaceMember::class)
                    ->findOneBy(['workspace' => $ws, 'user' => $user]) === null) {
                return null;
            }

            return $ws;
        }
        $membership = $this->em->getRepository(WorkspaceMember::class)->findOneBy(['user' => $user]);

        return $membership?->getWorkspace();
    }
}
