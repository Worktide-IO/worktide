<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Task;
use App\Entity\TaskAssignee;
use App\Entity\TaskOfferDismissal;
use App\Entity\User;
use App\Message\PlanScheduleMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Role-based ticket offers (Phase 2): unassigned, open tickets whose
 * requiredDiscipline matches the caller's discipline are offered to them; the
 * caller claims one (→ assigned + schedule re-planned) or declines it (persisted
 * so it stops showing). "Unassigned" = no User principal (a Team principal still
 * counts as unclaimed by a person).
 *
 *   GET  /v1/dashboard/task-offers
 *   POST /v1/tasks/{id}/claim
 *   POST /v1/tasks/{id}/decline-offer
 */
final class TaskOfferController
{
    use ResolvesWorkspaceMembership;

    private const MAX = 25;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route(path: '/v1/dashboard/task-offers', name: 'api_dashboard_task_offers', methods: ['GET'])]
    public function offers(Request $request): JsonResponse
    {
        [$user, $workspace] = $this->context($request);
        $discipline = $user->getDiscipline();
        if ($discipline === null) {
            // No discipline set → nothing can be matched to this staff member.
            return new JsonResponse(['offers' => [], 'needsDiscipline' => true]);
        }

        $qb = $this->em->createQueryBuilder();
        /** @var list<Task> $tasks */
        $tasks = $qb
            ->select('t', 'p', 'c')
            ->from(Task::class, 't')
            ->join('t.status', 's')
            ->leftJoin('t.project', 'p')
            ->leftJoin('p.customer', 'c')
            ->andWhere('t.workspace = :ws')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('s.isCompleted = false')
            ->andWhere('t.requiredDiscipline = :disc')
            ->andWhere($qb->expr()->not($qb->expr()->exists(
                'SELECT 1 FROM ' . TaskAssignee::class . ' ap WHERE ap.task = t AND ap.principalType = :ptype',
            )))
            ->andWhere($qb->expr()->not($qb->expr()->exists(
                'SELECT 1 FROM ' . TaskOfferDismissal::class . ' d WHERE d.task = t AND d.user = :uid',
            )))
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('disc', $discipline->value)
            ->setParameter('ptype', AssigneePrincipalType::User)
            ->setParameter('uid', $user->getId(), UuidType::NAME)
            ->orderBy('t.priorityScore', 'DESC')
            ->addOrderBy('t.dueOn', 'ASC')
            ->setMaxResults(self::MAX)
            ->getQuery()
            ->getResult();

        return new JsonResponse([
            'discipline' => $discipline->value,
            'offers' => array_map($this->dto(...), $tasks),
        ]);
    }

    #[Route(path: '/v1/tasks/{id}/claim', name: 'api_task_claim', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function claim(string $id, Request $request): JsonResponse
    {
        [$user, $workspace, $task] = $this->loadTask($id, $request);

        if ($task->getStatus()->isCompleted()) {
            throw new ConflictHttpException('This ticket is already completed.');
        }
        foreach ($task->getAssignedPrincipals() as $principal) {
            if ($principal->getPrincipalType() === AssigneePrincipalType::User) {
                throw new ConflictHttpException('This ticket has already been claimed.');
            }
        }

        $task->addAssignedPrincipal(
            (new TaskAssignee())
                ->setWorkspace($workspace)
                ->setPrincipalType(AssigneePrincipalType::User)
                ->setPrincipalId($user->getId() ?? throw new AccessDeniedHttpException()),
        );
        $this->em->flush();

        // Newly assigned → re-plan the claimer's schedule to fit it in.
        $this->bus->dispatch(new PlanScheduleMessage($user->getId(), $workspace->getId() ?? throw new AccessDeniedHttpException()));

        return new JsonResponse(['status' => 'claimed', 'taskId' => $id], Response::HTTP_CREATED);
    }

    #[Route(path: '/v1/tasks/{id}/decline-offer', name: 'api_task_decline_offer', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function decline(string $id, Request $request): JsonResponse
    {
        [$user, , $task] = $this->loadTask($id, $request);

        $existing = $this->em->getRepository(TaskOfferDismissal::class)->findOneBy(['user' => $user, 'task' => $task]);
        if ($existing === null) {
            $this->em->persist((new TaskOfferDismissal())->setUser($user)->setTask($task));
            $this->em->flush();
        }

        return new JsonResponse(['status' => 'declined', 'taskId' => $id]);
    }

    /** @return array{0: User, 1: \App\Entity\Workspace, 2: Task} */
    private function loadTask(string $id, Request $request): array
    {
        [$user, $workspace] = $this->context($request);
        $task = $this->em->find(Task::class, Uuid::fromString($id));
        if (!$task instanceof Task || !$task->getWorkspace()->getId()?->equals($workspace->getId())) {
            throw new NotFoundHttpException();
        }

        return [$user, $workspace, $task];
    }

    /** @return array{0: User, 1: \App\Entity\Workspace} */
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

        return [$user, $workspace];
    }

    /** @return array<string, mixed> */
    private function dto(Task $t): array
    {
        $project = $t->getProject();
        $customer = $project?->getCustomer();

        return [
            'id' => $t->getId()?->toRfc4122(),
            'identifier' => $t->getIdentifier(),
            'title' => $t->getTitle(),
            'priority' => $t->getPriority()->value,
            'priorityScore' => $t->getPriorityScore(),
            'estimatedMinutes' => $t->getEstimatedMinutes(),
            'requiredDiscipline' => $t->getRequiredDiscipline()?->value,
            'dueOn' => $t->getDueOn()?->format('Y-m-d'),
            'project' => $project === null ? null : ['id' => $project->getId()?->toRfc4122(), 'name' => $project->getName()],
            'customerName' => $customer?->getName(),
        ];
    }
}
