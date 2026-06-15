<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskStatusRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Security\Voter\WorktidePermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Generic CSV-import sink for customers / contacts / tasks. The SPA parses
 * the CSV client-side (papaparse) and POSTs the resolved row objects:
 *
 *   POST /v1/imports/<resource>
 *   {
 *     "rows": [
 *       { "name": "Acme", "email": "info@acme.example", ... },
 *       ...
 *     ],
 *     "dryRun": false   // when true: validate-only, persist nothing
 *   }
 *
 * Response:
 *   { "created": N, "skipped": N, "errors": [{row: int, message: string}] }
 *
 * Workspace is taken from X-Workspace-Id (validated against membership)
 * — no per-row workspace IRI needed. Per-row authorization for the
 * relevant create-on-workspace permission happens once at the start;
 * downstream voter checks per entity also apply on flush.
 */
final class ImportController
{
    private const MAX_ROWS = 5_000;

    private const SUPPORTED = ['customers', 'contacts', 'tasks'];

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly WorkspaceMemberRepository $wsMembers,
        private readonly ProjectRepository $projects,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly TaskRepository $tasks,
    ) {}

    #[Route(
        path: '/v1/imports/{resource}',
        name: 'api_imports',
        host: 'api.worktide.ddev.site',
        requirements: ['resource' => 'customers|contacts|tasks'],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $resource): JsonResponse
    {
        if (!in_array($resource, self::SUPPORTED, true)) {
            throw new BadRequestHttpException('Unsupported resource.');
        }
        $user = $this->requireUser();
        $workspace = $this->resolveWorkspace($request, $user);

        $body = $this->body($request);
        $rows = $body['rows'] ?? null;
        if (!is_array($rows)) {
            throw new BadRequestHttpException('rows[] required.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new BadRequestHttpException(
                sprintf('Too many rows — limit is %d per request.', self::MAX_ROWS),
            );
        }
        $dryRun = (bool) ($body['dryRun'] ?? false);

        $created = 0;
        $skipped = 0;
        $matched = 0;
        /** @var list<array{row: int, message: string}> $errors */
        $errors = [];

        // Cache the workspace's relations once — looked up by CSV row but
        // the same FK targets recur all over a typical import file.
        $projectsByKey = [];
        $taskStatusesByName = [];
        if ($resource === 'tasks') {
            foreach ($this->projects->findBy(['workspace' => $workspace]) as $p) {
                if ($p->getKey() !== '') {
                    $projectsByKey[strtolower($p->getKey())] = $p;
                }
            }
            foreach ($this->taskStatuses->findBy(['workspace' => $workspace]) as $s) {
                if ($s->getName() !== '') {
                    $taskStatusesByName[strtolower($s->getName())] = $s;
                }
            }
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $errors[] = ['row' => $i, 'message' => 'Not an object.'];
                $skipped++;
                continue;
            }
            try {
                $entity = match ($resource) {
                    'customers' => $this->buildCustomer($row, $workspace),
                    'contacts' => $this->buildContact($row, $workspace),
                    'tasks' => $this->buildTask(
                        $row,
                        $workspace,
                        $user,
                        $projectsByKey,
                        $taskStatusesByName,
                    ),
                    default => throw new \LogicException('unreachable'),
                };
                // buildTask returns null when an idempotent match already
                // exists — count as matched, not skipped (skipped = parse
                // failure, matched = "we know this row, no-op").
                if ($entity === null) {
                    $matched++;
                    continue;
                }
                if (!$dryRun) {
                    $this->em->persist($entity);
                }
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $i, 'message' => $e->getMessage()];
                $skipped++;
            }
        }

        if (!$dryRun && $created > 0) {
            try {
                $this->em->flush();
            } catch (\Throwable $e) {
                // The flush errored — wrap the whole import in a generic
                // failure rather than report misleading per-row stats.
                throw new BadRequestHttpException('Persist failed: ' . $e->getMessage());
            }
        }

        return new JsonResponse([
            'resource' => $resource,
            'created' => $created,
            'matched' => $matched,
            'skipped' => $skipped,
            'errors' => $errors,
            'dryRun' => $dryRun,
        ]);
    }

    private function requireUser(): User
    {
        $u = $this->security->getUser();
        if (!$u instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        return $u;
    }

    private function resolveWorkspace(Request $request, User $user): Workspace
    {
        $hdr = $request->headers->get('X-Workspace-Id');
        if (is_string($hdr) && $hdr !== '') {
            try {
                $ws = $this->em->find(Workspace::class, Uuid::fromString($hdr));
            } catch (\InvalidArgumentException) {
                $ws = null;
            }
            if ($ws !== null) {
                $membership = $this->wsMembers->findOneBy(['workspace' => $ws, 'user' => $user]);
                if ($membership === null) {
                    throw new BadRequestHttpException('Not a member of that workspace.');
                }
                return $ws;
            }
        }
        // Fall back to the user's first workspace membership.
        $first = $this->wsMembers->findOneBy(['user' => $user]);
        if ($first === null) {
            throw new BadRequestHttpException('User has no workspace.');
        }
        return $first->getWorkspace();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent() ?: '{}', true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Body must be JSON: ' . $e->getMessage());
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildCustomer(array $row, Workspace $workspace): Customer
    {
        $name = $this->str($row, 'name');
        if ($name === null || $name === '') {
            throw new \DomainException('name is required.');
        }
        $c = (new Customer())
            ->setWorkspace($workspace)
            ->setName($name)
            ->setLegalName($this->str($row, 'legalName'))
            ->setVatId($this->str($row, 'vatId'))
            ->setIndustry($this->str($row, 'industry'))
            ->setEmail($this->str($row, 'email'))
            ->setPhone($this->str($row, 'phone'))
            ->setWebsite($this->str($row, 'website'))
            ->setAddressLine1($this->str($row, 'addressLine1') ?? $this->str($row, 'street'))
            ->setAddressLine2($this->str($row, 'addressLine2') ?? $this->str($row, 'streetExtra'))
            ->setZip($this->str($row, 'zip') ?? $this->str($row, 'postalCode'))
            ->setCity($this->str($row, 'city'))
            ->setCountry($this->str($row, 'country') ?? 'DE');

        $statusStr = $this->str($row, 'status');
        if ($statusStr !== null) {
            $status = CustomerStatus::tryFrom($statusStr);
            if ($status === null) {
                throw new \DomainException("Unknown status '$statusStr'.");
            }
            $c->setStatus($status);
        }
        if (array_key_exists('isCompany', $row)) {
            $c->setIsCompany((bool) $row['isCompany']);
        }
        return $c;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildContact(array $row, Workspace $workspace): Contact
    {
        $first = $this->str($row, 'firstName');
        $last = $this->str($row, 'lastName');
        $customerRef = $this->str($row, 'customer'); // name OR uuid
        if ($first === null && $last === null) {
            throw new \DomainException('firstName or lastName required.');
        }
        if ($customerRef === null) {
            throw new \DomainException('customer (name or id) required.');
        }
        $customer = $this->resolveCustomer($customerRef, $workspace);
        if ($customer === null) {
            throw new \DomainException("Customer '$customerRef' not found.");
        }
        $c = (new Contact())
            ->setWorkspace($workspace)
            ->setCustomer($customer)
            ->setFirstName($first ?? '')
            ->setLastName($last ?? '')
            ->setTitle($this->str($row, 'title'))
            ->setPosition($this->str($row, 'position'))
            ->setEmail($this->str($row, 'email'))
            ->setPhone($this->str($row, 'phone'))
            ->setMobile($this->str($row, 'mobile'))
            ->setSalutation($this->str($row, 'salutation'));
        if (array_key_exists('isPrimary', $row)) {
            $c->setIsPrimary((bool) $row['isPrimary']);
        }
        return $c;
    }

    /**
     * Build a Task from a CSV row.
     *
     * Returns `null` when the row's `correlationId` matches an existing
     * task in the workspace — the caller treats that as an idempotent
     * match (re-import-friendly) rather than a parse failure.
     *
     * @param array<string, mixed> $row
     * @param array<string, Project> $projectsByKey
     * @param array<string, TaskStatus> $taskStatusesByName
     */
    private function buildTask(
        array $row,
        Workspace $workspace,
        User $author,
        array $projectsByKey,
        array $taskStatusesByName,
    ): ?Task {
        // correlationId is the first thing we look at: if the importer
        // pre-stamps each row with a UUID derived from the source system
        // (e.g. JIRA-1234 → uuidv5), re-runs become safe replays.
        $correlationId = null;
        $cidRaw = $this->str($row, 'correlationId');
        if ($cidRaw !== null) {
            try {
                $correlationId = Uuid::fromString($cidRaw);
            } catch (\InvalidArgumentException) {
                throw new \DomainException("Invalid correlationId '$cidRaw' (must be UUID).");
            }
            $existing = $this->tasks->findOneBy([
                'workspace' => $workspace,
                'correlationId' => $correlationId,
            ]);
            if ($existing !== null) {
                return null;
            }
        }

        $title = $this->str($row, 'title');
        if ($title === null || $title === '') {
            throw new \DomainException('title is required.');
        }

        // Project + status are looked up by KEY/NAME respectively so a
        // CSV like "WORK,Bug,Fix login,…" is far friendlier than uuids.
        $projectKey = $this->str($row, 'project');
        $project = null;
        if ($projectKey !== null) {
            $project = $projectsByKey[strtolower($projectKey)] ?? null;
            if ($project === null) {
                throw new \DomainException("Project '$projectKey' not found.");
            }
            if (!$this->security->isGranted(WorktidePermission::EDIT, $project)) {
                throw new \DomainException("No permission to add tasks to project '$projectKey'.");
            }
        }

        $statusName = $this->str($row, 'status');
        $status = null;
        if ($statusName !== null) {
            $status = $taskStatusesByName[strtolower($statusName)] ?? null;
            if ($status === null) {
                throw new \DomainException("Status '$statusName' not found.");
            }
        } else {
            // Pick the default status (lowest position) or any status as a
            // fallback — Task.status is non-nullable.
            $sorted = array_values($taskStatusesByName);
            usort($sorted, fn($a, $b) => $a->getPosition() <=> $b->getPosition());
            $status = $sorted[0] ?? null;
            if ($status === null) {
                throw new \DomainException('Workspace has no task statuses.');
            }
        }

        $task = (new Task())
            ->setWorkspace($workspace)
            ->setProject($project)
            ->setTitle($title)
            ->setDescription($this->str($row, 'description'))
            ->setStatus($status)
            ->setCreatedBy($author)
            ->setCreatedVia(TaskCreatedVia::Import)
            ->setCorrelationId($correlationId);

        $priorityStr = $this->str($row, 'priority');
        if ($priorityStr !== null) {
            $prio = TaskPriority::tryFrom($priorityStr);
            if ($prio === null) {
                throw new \DomainException("Unknown priority '$priorityStr'.");
            }
            $task->setPriority($prio);
        }

        // Identifier: keep what the CSV provided if any, otherwise mint one
        // from the project key + sequence. Backend-side sequence isn't
        // exposed via the entity so the simpler choice is just NOW()-time
        // based when missing.
        $identifier = $this->str($row, 'identifier');
        if ($identifier !== null) {
            $task->setIdentifier($identifier);
        } else {
            $task->setIdentifier(
                ($project?->getKey() ?? 'TASK') . '-' . dechex(random_int(0x1000, 0xFFFF)),
            );
        }

        $due = $this->str($row, 'dueOn');
        if ($due !== null) {
            try {
                $task->setDueOn(new \DateTimeImmutable($due));
            } catch (\Throwable) {
                throw new \DomainException("Invalid dueOn '$due'.");
            }
        }

        return $task;
    }

    private function resolveCustomer(string $ref, Workspace $workspace): ?Customer
    {
        // Try uuid first, then exact name.
        try {
            $uuid = Uuid::fromString($ref);
            $c = $this->em->find(Customer::class, $uuid);
            if ($c !== null && $c->getWorkspace() === $workspace) {
                return $c;
            }
        } catch (\InvalidArgumentException) {
            // not a uuid — fall through
        }
        $repo = $this->em->getRepository(Customer::class);
        return $repo->findOneBy(['workspace' => $workspace, 'name' => $ref]);
    }

    /**
     * Read a string field from a row — trims, returns null on empty or
     * non-scalar so the entity setters get clean nullable input.
     *
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $field): ?string
    {
        $v = $row[$field] ?? null;
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
