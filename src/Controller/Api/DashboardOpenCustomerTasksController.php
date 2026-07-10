<?php

declare(strict_types=1);

namespace App\Controller\Api;

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
 * Dashboard read-model for the "Offene Kunden-Aufgaben" widget.
 *
 * Was: fetch the WHOLE tasks + projects + task_statuses collections
 * (pagination:off) and filter client-side to "open task whose project has a
 * customer". This returns exactly that set, workspace-scoped, with the project
 * + customer summary inlined — one scoped request instead of three fetch-alls.
 *
 *   GET /v1/dashboard/open-customer-tasks
 *     &workspace=<uuid>   (defaults to the caller's first membership;
 *                          X-Workspace-Id header honoured too)
 *
 * Response:
 *   {
 *     "tasks": [ { "@id","id","identifier","title","priority","dueOn"|null,
 *                  "project": {"@id","id","name","color"},
 *                  "customer": {"id","name"} } ],
 *     "capped": bool
 *   }
 */
final class DashboardOpenCustomerTasksController
{
    private const CAP = 200;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(path: '/v1/dashboard/open-customer-tasks', name: 'api_dashboard_open_customer_tasks', methods: ['GET'])]
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

        // Inner-join project + customer so only tasks whose project has a
        // customer come back; open = status not completed.
        $tasks = $this->em->createQueryBuilder()
            ->select('t', 's', 'p', 'c')
            ->from(Task::class, 't')
            ->join('t.status', 's')
            ->join('t.project', 'p')
            ->join('p.customer', 'c')
            ->andWhere('t.workspace = :ws')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('s.isCompleted = false')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
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
        // project + customer are guaranteed non-null by the inner joins.
        $project = $t->getProject();
        \assert($project !== null);
        $customer = $project->getCustomer();
        \assert($customer !== null);

        return [
            '@id' => '/v1/tasks/' . $t->getId()->toRfc4122(),
            'id' => $t->getId()->toRfc4122(),
            'identifier' => $t->getIdentifier(),
            'title' => $t->getTitle(),
            'priority' => $t->getPriority()->value,
            'dueOn' => $t->getDueOn()?->format(\DateTimeInterface::ATOM),
            'project' => [
                '@id' => '/v1/projects/' . $project->getId()->toRfc4122(),
                'id' => $project->getId()->toRfc4122(),
                'name' => $project->getName(),
                'color' => $project->getColor(),
            ],
            'customer' => [
                'id' => $customer->getId()->toRfc4122(),
                'name' => $customer->getName(),
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
            // Membership-checked: never trust a client-supplied workspace id.
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
