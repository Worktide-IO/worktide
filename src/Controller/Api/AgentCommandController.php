<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Api\Concern\ResolvesWorkspaceMembership;
use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Absence;
use App\Entity\Customer;
use App\Entity\Enum\Capability;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Product;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\GenerateMarketingCopyMessage;
use App\Message\PlanScheduleMessage;
use App\Security\PermissionResolver;
use App\Service\Ai\AgentCommandRouter;
use App\Service\Llm\LlmException;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * General AI command bar on the staff dashboard (confirm-first):
 *
 *   POST /v1/me/agent-command          { text }   → clarify | proposal
 *   POST /v1/me/agent-command/execute  { intent, ...fields } → performs the action
 *
 * The router classifies the free text into one of a small, fixed intent set and
 * extracts names; this controller resolves names to workspace entities and
 * returns a concrete proposal the staff confirms. Execution only happens on the
 * explicit second call. Marketing runs as an egress-gated draft; the absence
 * re-plans the schedule.
 */
final class AgentCommandController
{
    use ResolvesWorkspaceMembership;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly AgentCommandRouter $router,
        private readonly EgressGuard $egress,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * The assistant acts strictly AS the user: an intent is only allowed when the
     * user could perform that action directly. No escalation — a member can't
     * create tickets via the AI if their role can't; an admin/manager can, because
     * their role grants it. Absence is self-service (own record), always allowed.
     */
    private function canDo(string $intent, User $user, Workspace $workspace): bool
    {
        return match ($intent) {
            'absence' => true,
            'create_ticket' => $this->permissions->can($user, Capability::TaskCreate, $workspace),
            'promote_product' => $this->security->isGranted(WorktidePermission::EDIT, $workspace),
            default => false,
        };
    }

    #[Route(path: '/v1/me/agent-command', name: 'api_me_agent_command', methods: ['POST'])]
    public function command(Request $request): JsonResponse
    {
        [$user, $workspace] = $this->context($request);
        $text = $this->stringField($request, 'text');
        if ($text === '') {
            throw new BadRequestHttpException('text required.');
        }
        if (!$this->router->isAvailable() || !$this->egress->isAllowed(EgressModule::Llm)) {
            throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'AI is not configured / LLM egress not approved.');
        }

        try {
            $r = $this->router->route($text, $workspace);
        } catch (LlmException) {
            throw new HttpException(Response::HTTP_BAD_GATEWAY, 'The AI provider failed to interpret the command.');
        }

        $clarify = fn (string $q): JsonResponse => new JsonResponse(['intent' => 'clarify', 'question' => $q]);

        if ($r['intent'] === 'clarify') {
            return $clarify($r['clarify'] ?? 'Kannst du das genauer beschreiben?');
        }

        // The assistant may only propose what this user could do themselves.
        if (!$this->canDo($r['intent'], $user, $workspace)) {
            return new JsonResponse(['intent' => 'denied', 'message' => 'Dafür fehlt dir die Berechtigung.']);
        }

        if ($r['intent'] === 'absence') {
            if ($r['startsOn'] === null || $r['endsOn'] === null || $r['clarify'] !== null) {
                return $clarify($r['clarify'] ?? 'Von wann bis wann bist du abwesend?');
            }
            return new JsonResponse(['intent' => 'absence', 'proposal' => [
                'startsOn' => $r['startsOn'], 'endsOn' => $r['endsOn'], 'type' => $r['absenceType'],
                'availabilityPercent' => $r['availabilityPercent'],
            ]]);
        }

        if ($r['intent'] === 'promote_product') {
            if ($r['productName'] === null) {
                return $clarify('Welches Produkt soll beworben werden?');
            }
            [$product, $why] = $this->resolveByName(Product::class, $r['productName'], $workspace);
            if (!$product instanceof Product) {
                return $clarify($why === 'ambiguous'
                    ? sprintf('Mehrere Produkte passen zu „%s". Welches genau?', $r['productName'])
                    : sprintf('Ich finde kein Produkt „%s". Wie heißt es genau?', $r['productName']));
            }
            return new JsonResponse(['intent' => 'promote_product', 'proposal' => [
                'productId' => $product->getId()?->toRfc4122(), 'productName' => $product->getName(),
            ]]);
        }

        // create_ticket — resolve a target project (required).
        $title = $r['title'] ?? ($text !== '' ? mb_substr($text, 0, 120) : null);
        $project = null;
        if ($r['projectName'] !== null) {
            [$project, $why] = $this->resolveByName(Project::class, $r['projectName'], $workspace);
            if (!$project instanceof Project) {
                return $clarify(sprintf('%s Welches Projekt genau?', $why === 'ambiguous' ? 'Mehrere Projekte passen.' : sprintf('Kein Projekt „%s" gefunden.', $r['projectName'])));
            }
        } elseif ($r['customerName'] !== null) {
            [$customer, $why] = $this->resolveByName(Customer::class, $r['customerName'], $workspace);
            if (!$customer instanceof Customer) {
                return $clarify(sprintf('Ich finde den Kunden „%s" nicht eindeutig. In welches Projekt soll das Ticket?', $r['customerName']));
            }
            $projects = $this->em->getRepository(Project::class)->findBy(['workspace' => $workspace, 'customer' => $customer, 'deletedAt' => null]);
            if (\count($projects) === 1) {
                $project = $projects[0];
            } else {
                return $clarify(sprintf('%s hat %d Projekte. In welches soll das Ticket?', $customer->getName(), \count($projects)));
            }
        } else {
            return $clarify('Für welches Projekt oder welchen Kunden soll ich das Ticket anlegen?');
        }

        return new JsonResponse(['intent' => 'create_ticket', 'proposal' => [
            'title' => $title,
            'description' => $r['description'],
            'projectId' => $project->getId()?->toRfc4122(),
            'projectName' => $project->getName(),
            'customerName' => $project->getCustomer()?->getName(),
        ]]);
    }

    #[Route(path: '/v1/me/agent-command/execute', name: 'api_me_agent_command_execute', methods: ['POST'])]
    public function execute(Request $request): JsonResponse
    {
        [$user, $workspace] = $this->context($request);
        $body = json_decode($request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $intent = \is_string($body['intent'] ?? null) ? $body['intent'] : '';

        // Hard gate: never execute an action the user isn't allowed to perform.
        if (!$this->canDo($intent, $user, $workspace)) {
            throw new AccessDeniedHttpException('You are not allowed to perform this action.');
        }

        return match ($intent) {
            'absence' => $this->executeAbsence($user, $workspace, $body),
            'create_ticket' => $this->executeCreateTicket($user, $workspace, $body),
            'promote_product' => $this->executePromoteProduct($workspace, $body),
            default => throw new BadRequestHttpException('Unknown intent.'),
        };
    }

    /** @param array<string, mixed> $body */
    private function executeAbsence(User $user, Workspace $workspace, array $body): JsonResponse
    {
        $start = $this->dateField($body, 'startsOn');
        $end = $this->dateField($body, 'endsOn');
        if ($start === null || $end === null) {
            throw new BadRequestHttpException('startsOn/endsOn required.');
        }
        $type = \in_array($body['type'] ?? null, ['sick', 'child_sick', 'vacation', 'other'], true) ? $body['type'] : 'sick';
        $availabilityPercent = \is_numeric($body['availabilityPercent'] ?? null) ? (int) $body['availabilityPercent'] : 0;

        $absence = (new Absence())
            ->setWorkspace($workspace)->setUser($user)
            ->setStartsOn($start->setTime(0, 0))->setEndsOn($end->setTime(0, 0))->setType($type)
            ->setAvailabilityPercent($availabilityPercent);
        $this->em->persist($absence);
        $this->em->flush();

        $uid = $user->getId();
        $wid = $workspace->getId();
        if ($uid !== null && $wid !== null) {
            $this->bus->dispatch(new PlanScheduleMessage($uid, $wid));
        }

        return new JsonResponse(['status' => 'absence_created', 'absenceId' => $absence->getId()?->toRfc4122()], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $body */
    private function executeCreateTicket(User $user, Workspace $workspace, array $body): JsonResponse
    {
        $project = $this->requireOwned(Project::class, $body['projectId'] ?? null, $workspace);
        $title = \is_string($body['title'] ?? null) ? trim($body['title']) : '';
        if ($title === '') {
            throw new BadRequestHttpException('title required.');
        }
        $description = \is_string($body['description'] ?? null) ? $body['description'] : null;

        $task = (new Task())
            ->setWorkspace($workspace)
            ->setProject($project)
            ->setTitle(mb_substr($title, 0, 200))
            ->setDescription($description)
            ->setStatus($this->defaultStatus($workspace))
            ->setIdentifier($project->getKey() . '-' . dechex(random_int(0x1000, 0xFFFF)))
            ->setCreatedVia(TaskCreatedVia::Api);
        $this->em->persist($task);
        $this->em->flush();

        return new JsonResponse([
            'status' => 'ticket_created',
            'taskId' => $task->getId()?->toRfc4122(),
            'identifier' => $task->getIdentifier(),
        ], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $body */
    private function executePromoteProduct(Workspace $workspace, array $body): JsonResponse
    {
        $product = $this->requireOwned(Product::class, $body['productId'] ?? null, $workspace);
        $pid = $product->getId();
        if ($pid === null) {
            throw new NotFoundHttpException();
        }
        $this->bus->dispatch(new GenerateMarketingCopyMessage($pid));

        return new JsonResponse(['status' => 'marketing_queued', 'productId' => $pid->toRfc4122()], Response::HTTP_ACCEPTED);
    }

    private function defaultStatus(Workspace $workspace): TaskStatus
    {
        $repo = $this->em->getRepository(TaskStatus::class);
        $status = $repo->findOneBy(['workspace' => $workspace, 'isDefault' => true])
            ?? ($repo->findBy(['workspace' => $workspace], ['position' => 'ASC'], 1)[0] ?? null);
        if (!$status instanceof TaskStatus) {
            throw new ConflictHttpException('Workspace has no task statuses.');
        }

        return $status;
    }

    /**
     * Fuzzy name → single workspace entity.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return array{0: ?T, 1: string} entity + status: 'ok'|'none'|'ambiguous'
     */
    private function resolveByName(string $class, string $name, Workspace $workspace): array
    {
        /** @var list<object> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('e')->from($class, 'e')
            ->andWhere('e.workspace = :ws')
            ->andWhere('LOWER(e.name) LIKE :q')
            ->setParameter('ws', $workspace)
            ->setParameter('q', '%' . mb_strtolower($name) . '%')
            ->setMaxResults(5)
            ->getQuery()->getResult();

        if ($rows === []) {
            return [null, 'none'];
        }
        // Prefer an exact (case-insensitive) match if present.
        foreach ($rows as $row) {
            if (mb_strtolower((string) $row->getName()) === mb_strtolower($name)) {
                return [$row, 'ok'];
            }
        }

        return \count($rows) === 1 ? [$rows[0], 'ok'] : [null, 'ambiguous'];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function requireOwned(string $class, mixed $id, Workspace $workspace): object
    {
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new BadRequestHttpException('Missing/invalid id.');
        }
        $entity = $this->em->find($class, Uuid::fromString($id));
        if ($entity === null || !method_exists($entity, 'getWorkspace') || !$entity->getWorkspace()->getId()?->equals($workspace->getId())) {
            throw new NotFoundHttpException();
        }

        return $entity;
    }

    /** @return array{0: User, 1: Workspace} */
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

    private function stringField(Request $request, string $key): string
    {
        $body = json_decode($request->getContent(), true);
        $v = \is_array($body) ? ($body[$key] ?? null) : null;

        return \is_string($v) ? trim($v) : '';
    }

    /** @param array<string, mixed> $body */
    private function dateField(array $body, string $key): ?\DateTimeImmutable
    {
        $v = $body[$key] ?? null;
        if (!\is_string($v)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));

        return $d instanceof \DateTimeImmutable ? $d : null;
    }
}
