<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\ProjectMemberRole;
use App\Entity\Enum\TaskPriority;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectStatus;
use App\Entity\ProjectType;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\TimeEntry;
use App\Entity\TypeOfWork;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Reads the JSON snapshot from var/awork-snapshot/ and replicates the awork
 * account into a dedicated Worktide workspace. All inserts are idempotent
 * via (externalSource='awork', externalId=<awork uuid>).
 *
 * Mapping notes:
 *   - duration values: awork uses seconds, Worktide uses minutes
 *   - awork ProjectStatus has type ∈ {not-started, progress, stuck, closed}
 *     → Worktide ProjectStatus.isCompleted = (type === 'closed')
 *   - awork TaskStatus has type ∈ {todo, progress, done}
 *     → Worktide TaskStatus.isCompleted = (type === 'done')
 *   - awork ProjectMember.isResponsible=true → Worktide ProjectMemberRole::Manager
 *     (everything else → Contributor)
 *   - awork TaskStatus is per-project — Worktide collapses to workspace-scoped
 *     and dedupes by (name, type) so all 10 imported projects share one set
 *   - external users (`isExternal=true`) are skipped to keep the user pool clean
 *   - parent task FKs are wired in a 2nd pass after all tasks exist
 */
#[AsCommand(
    name: 'app:awork:import',
    description: 'Replicate the awork snapshot (var/awork-snapshot/) into a dedicated Worktide workspace.',
)]
final class AworkImportCommand extends Command
{
    private const EXTERNAL_SOURCE = 'awork';

    /**
     * Awork IDs are RFC-4122 UUIDs; UuidBindingMiddleware would auto-pack them
     * into BINARY(16) on the way into the VARCHAR external_id column. Prefix
     * with a fixed sentinel so the regex in UuidBindingMiddleware no longer
     * matches, and external_id stays a normal string.
     */
    private static function ref(string $aworkUuid): string
    {
        return 'aw:' . $aworkUuid;
    }

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('snapshot-dir', null, InputOption::VALUE_REQUIRED, 'Snapshot directory', 'var/awork-snapshot')
            ->addOption('workspace-name', null, InputOption::VALUE_REQUIRED, 'Target workspace name', 'WapplerSystems (awork)')
            ->addOption('workspace-slug', null, InputOption::VALUE_REQUIRED, 'Target workspace slug', 'wapplersystems-awork')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Roll back the transaction at the end');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $this->projectDir . '/' . $input->getOption('snapshot-dir');
        if (!is_dir($dir)) {
            $io->error("Snapshot dir not found: $dir");
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $users = $this->readJson("$dir/users.json");
        $projectTypes = $this->readJson("$dir/projecttypes.json");
        $projectStatuses = $this->readJson("$dir/projectstatuses.json");
        $picked = $this->readJson("$dir/picked-ids.json");
        $companies = file_exists("$dir/companies.json") ? $this->readJson("$dir/companies.json") : [];

        $this->em->beginTransaction();
        try {
            $workspace = $this->ensureWorkspace($input->getOption('workspace-name'), $input->getOption('workspace-slug'));
            $io->section('Workspace');
            $io->writeln(sprintf('  %s (%s)', $workspace->getName(), $workspace->getSlug()));

            $userMap = $this->importUsers($users, $workspace);
            $io->writeln(sprintf('Users: %d imported / mapped', count($userMap)));

            $projectTypeMap = $this->importProjectTypes($projectTypes, $workspace);
            $io->writeln(sprintf('ProjectTypes: %d', count($projectTypeMap)));

            $projectStatusMap = $this->importProjectStatuses($projectStatuses, $workspace);
            $io->writeln(sprintf('ProjectStatuses: %d', count($projectStatusMap)));

            $taskStatusMap = $this->importPerProjectTaskStatuses($dir, $picked, $workspace);
            $io->writeln(sprintf('TaskStatuses (workspace-collapsed): %d', count($taskStatusMap)));

            $customerMap = $this->importCustomers($companies, $workspace);
            $io->writeln(sprintf('Customers: %d', count($customerMap)));

            $this->em->flush();

            $projectMap = [];
            $taskMap = [];
            $aworkTasks = [];
            $typeOfWorkMap = [];

            foreach ($picked as $picksRow) {
                $pid = $picksRow['id'];
                $pdata = $this->readJson("$dir/projects/$pid.json");
                $tdata = $this->readJson("$dir/tasks/$pid.json");

                $project = $this->upsertProject(
                    $pdata,
                    $workspace,
                    $userMap,
                    $projectTypeMap,
                    $projectStatusMap,
                    $customerMap,
                );
                $projectMap[$pid] = $project;

                $this->upsertProjectMembers($pdata['members'] ?? [], $project, $userMap);

                foreach ($tdata as $taskRow) {
                    // resolves and persists TypeOfWork in workspace cache, even though
                    // Worktide's Task entity does not currently link to TypeOfWork
                    // (it lives on TimeEntry); this seeds the workspace catalogue.
                    $this->resolveTypeOfWork($taskRow, $workspace, $typeOfWorkMap);
                    $task = $this->upsertTask($taskRow, $project, $userMap, $taskStatusMap);
                    $taskMap[$taskRow['id']] = $task;
                    $aworkTasks[$taskRow['id']] = $taskRow;
                }

                $teCount = 0;
                $tePath = "$dir/time-entries/$pid.json";
                if (is_file($tePath)) {
                    $tedata = $this->readJson($tePath);
                    $teCount = $this->importTimeEntries($tedata, $project, $userMap, $typeOfWorkMap);
                }

                $io->writeln(sprintf(
                    '  · %s — %s: %d members, %d tasks, %d time entries',
                    $project->getKey(),
                    $project->getName(),
                    count($pdata['members'] ?? []),
                    count($tdata),
                    $teCount,
                ));
            }

            $this->em->flush();

            // 2nd pass — wire parent FKs (subtasks)
            $linked = 0;
            foreach ($aworkTasks as $awid => $row) {
                $parentId = $row['parentTaskId'] ?? null;
                if ($parentId !== null && isset($taskMap[$parentId]) && isset($taskMap[$awid])) {
                    $taskMap[$awid]->setParent($taskMap[$parentId]);
                    $linked++;
                }
            }
            if ($linked > 0) {
                $this->em->flush();
            }
            $io->writeln(sprintf('Subtask links wired: %d', $linked));

            if ($dryRun) {
                $this->em->rollback();
                $io->warning('Dry-run — transaction rolled back.');
            } else {
                $this->em->commit();
                $io->success('Import committed.');
            }
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            $io->error($e->getMessage());
            $io->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read $path");
        }
        return json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    }

    private function ensureWorkspace(string $name, string $slug): Workspace
    {
        $repo = $this->em->getRepository(Workspace::class);
        $existing = $repo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE]);
        if ($existing !== null) {
            return $existing;
        }
        $ws = (new Workspace())
            ->setName($name)
            ->setSlug($slug)
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setExternalSource(self::EXTERNAL_SOURCE)
            ->setExternalId('workspace');
        $this->em->persist($ws);
        return $ws;
    }

    /**
     * @param array<array<string,mixed>> $rows
     * @return array<string, User>  awork userId → User
     */
    private function importUsers(array $rows, Workspace $workspace): array
    {
        $repo = $this->em->getRepository(User::class);
        $wsMemberRepo = $this->em->getRepository(WorkspaceMember::class);
        $map = [];

        $totalRows = 0;
        $createdRows = 0;
        foreach ($rows as $r) {
            $totalRows++;
            if (($r['isExternal'] ?? false) === true) {
                continue;
            }
            $email = null;
            foreach (($r['userContactInfos'] ?? []) as $ci) {
                if (($ci['type'] ?? null) === 'email' && !empty($ci['value'])) {
                    $email = $ci['value'];
                    break;
                }
            }
            if (!$email) {
                continue;
            }
            $awid = $r['id'];

            $u = $repo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => self::ref($awid)]);
            if ($u === null) {
                $u = $repo->findOneBy(['email' => $email]);
            }
            if ($u === null) {
                $u = (new User())
                    ->setEmail($email)
                    ->setFirstName($r['firstName'] ?? '')
                    ->setLastName($r['lastName'] ?? '');
                $u->setPassword($this->hasher->hashPassword($u, 'imported-' . bin2hex(random_bytes(12))));
                $createdRows++;
            }
            $u->setExternalSource(self::EXTERNAL_SOURCE);
            $u->setExternalId(self::ref($awid));
            $this->em->persist($u);

            // workspace membership: Sven is the awork owner (eb0e5771-aa32-ec11-ae72-501ac527a4b8)
            $role = $awid === 'eb0e5771-aa32-ec11-ae72-501ac527a4b8'
                ? WorkspaceMemberRole::Owner
                : WorkspaceMemberRole::Member;

            $member = $wsMemberRepo->findOneBy(['workspace' => $workspace, 'user' => $u]);
            if ($member === null) {
                $member = (new WorkspaceMember())
                    ->setWorkspace($workspace)
                    ->setUser($u)
                    ->setRole($role);
                $this->em->persist($member);
            } else {
                $member->setRole($role);
            }
            $map[$awid] = $u;
        }
        return $map;
    }

    /**
     * @param array<array<string,mixed>> $rows
     * @return array<string, ProjectType>
     */
    private function importProjectTypes(array $rows, Workspace $workspace): array
    {
        $repo = $this->em->getRepository(ProjectType::class);
        $map = [];
        foreach ($rows as $r) {
            $awid = $r['id'];
            $type = $repo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => self::ref($awid)]);
            if ($type === null) {
                $type = (new ProjectType())
                    ->setWorkspace($workspace)
                    ->setName($r['name'])
                    ->setIcon($r['icon'] ?? null)
                    ->setIsArchived((bool) ($r['isArchived'] ?? false));
                $type->setExternalSource(self::EXTERNAL_SOURCE);
                $type->setExternalId(self::ref($awid));
                $this->em->persist($type);
            }
            $map[$awid] = $type;
        }
        return $map;
    }

    /**
     * @param array<array<string,mixed>> $rows
     * @return array<string, ProjectStatus>  awork id → ProjectStatus (workspace-deduped)
     */
    private function importProjectStatuses(array $rows, Workspace $workspace): array
    {
        $repo = $this->em->getRepository(ProjectStatus::class);
        $map = [];
        $byName = [];

        $position = 0;
        foreach ($rows as $r) {
            $name = trim($r['name'] ?? '');
            $type = $r['type'] ?? 'progress';
            if ($name === '') {
                continue;
            }
            $awid = $r['id'];

            $key = mb_strtolower($name) . '|' . $type;
            if (isset($byName[$key])) {
                $map[$awid] = $byName[$key];
                continue;
            }

            $status = $repo->findOneBy(['workspace' => $workspace, 'name' => $name]);
            if ($status === null) {
                $status = (new ProjectStatus())
                    ->setWorkspace($workspace)
                    ->setName($name)
                    ->setColor($this->mapColor($r['color'] ?? null, '#94a3b8'))
                    ->setIsCompleted($type === 'closed')
                    ->setPosition(++$position);
                $status->setExternalSource(self::EXTERNAL_SOURCE);
                $status->setExternalId(self::ref($awid));
                $this->em->persist($status);
            }
            $byName[$key] = $status;
            $map[$awid] = $status;
        }
        return $map;
    }

    /**
     * Map awork Companies → Worktide Customers. Picks the first email /
     * phone / url / address entry off the awork contactInfos array (each
     * has a `type` discriminator) and stuffs them into the flat Customer
     * fields. Address strings come in awork as freeform single-line text;
     * we drop them into addressLine1 verbatim.
     *
     * @param array<array<string,mixed>> $rows
     * @return array<string, Customer>  awork company id → Customer
     */
    private function importCustomers(array $rows, Workspace $workspace): array
    {
        $repo = $this->em->getRepository(Customer::class);
        $map = [];
        foreach ($rows as $r) {
            $awid = $r['id'] ?? null;
            if (!\is_string($awid)) {
                continue;
            }
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $customer = $repo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => self::ref($awid)]);
            if ($customer === null) {
                $customer = new Customer();
            }

            $email = $phone = $website = $address = null;
            foreach (($r['companyContactInfos'] ?? []) as $ci) {
                $type = $ci['type'] ?? null;
                $value = $ci['value'] ?? null;
                if (!\is_string($value) || $value === '') {
                    continue;
                }
                match ($type) {
                    'email' => $email ??= $value,
                    'phone' => $phone ??= $value,
                    'url'   => $website ??= $value,
                    'address' => $address ??= $value,
                    default => null,
                };
            }

            $customer
                ->setWorkspace($workspace)
                ->setName($name)
                ->setLegalName($name)
                ->setIsCompany(true)
                ->setIndustry(($r['industry'] ?? '') !== '' ? (string) $r['industry'] : null)
                ->setEmail($email)
                ->setPhone($phone)
                ->setWebsite(self::sanitizeUrl($website))
                ->setAddressLine1($address)
                ->setStatus(($r['isExternal'] ?? false) ? CustomerStatus::Archived : CustomerStatus::Active);
            $customer->setExternalSource(self::EXTERNAL_SOURCE);
            $customer->setExternalId(self::ref($awid));
            $this->em->persist($customer);
            $map[$awid] = $customer;
        }
        return $map;
    }

    private static function sanitizeUrl(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $value) !== 1) {
            $value = 'https://' . $value;
        }
        return filter_var($value, \FILTER_VALIDATE_URL) === false ? null : $value;
    }

    /**
     * Walk every picked project's taskstatuses.json, dedupe by (lower(name), type)
     * across all projects, persist as workspace-scoped TaskStatuses.
     *
     * @param array<array<string,mixed>> $picked
     * @return array<string, TaskStatus>  awork task-status id → TaskStatus
     */
    private function importPerProjectTaskStatuses(string $dir, array $picked, Workspace $workspace): array
    {
        $repo = $this->em->getRepository(TaskStatus::class);
        $map = [];
        $byKey = [];
        $position = 0;

        foreach ($picked as $row) {
            $pid = $row['id'];
            $path = "$dir/projects/$pid.taskstatuses.json";
            if (!is_file($path)) {
                continue;
            }
            $statuses = $this->readJson($path);
            foreach ($statuses as $s) {
                $name = trim($s['name'] ?? '');
                $type = $s['type'] ?? 'todo';
                if ($name === '') {
                    continue;
                }
                $awid = $s['id'];
                $key = mb_strtolower($name) . '|' . $type;

                if (isset($byKey[$key])) {
                    $map[$awid] = $byKey[$key];
                    continue;
                }

                $ts = $repo->findOneBy(['workspace' => $workspace, 'name' => $name]);
                if ($ts === null) {
                    $ts = (new TaskStatus())
                        ->setWorkspace($workspace)
                        ->setName($name)
                        ->setColor($this->mapColor($s['color'] ?? null, '#94a3b8'))
                        ->setIsCompleted($type === 'done')
                        ->setPosition(++$position);
                    $ts->setExternalSource(self::EXTERNAL_SOURCE);
                    $ts->setExternalId(self::ref($awid));
                    $this->em->persist($ts);
                }
                $byKey[$key] = $ts;
                $map[$awid] = $ts;
            }
        }

        // Mark the lowest-position todo status as default if no default exists
        if ($map !== []) {
            $hasDefault = false;
            foreach ($byKey as $ts) {
                if ($ts->isDefault()) {
                    $hasDefault = true;
                    break;
                }
            }
            if (!$hasDefault && isset($byKey['backlog|todo'])) {
                $byKey['backlog|todo']->setIsDefault(true);
            } elseif (!$hasDefault) {
                // first inserted
                reset($byKey);
                current($byKey)->setIsDefault(true);
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, User> $userMap
     * @param array<string, ProjectType> $projectTypeMap
     * @param array<string, ProjectStatus> $projectStatusMap
     */
    /**
     * @param array<string, mixed> $p
     * @param array<string, User> $userMap
     * @param array<string, ProjectType> $projectTypeMap
     * @param array<string, ProjectStatus> $projectStatusMap
     * @param array<string, Customer> $customerMap
     */
    private function upsertProject(
        array $p,
        Workspace $workspace,
        array $userMap,
        array $projectTypeMap,
        array $projectStatusMap,
        array $customerMap = [],
    ): Project {
        $repo = $this->em->getRepository(Project::class);
        $awid = $p['id'];

        $project = $repo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => self::ref($awid)]);
        if ($project === null) {
            $project = new Project();
        }

        $status = $projectStatusMap[$p['projectStatusId'] ?? ''] ?? null;
        if ($status === null) {
            // fall back to any workspace status — should not happen with our snapshot
            $status = $this->em->getRepository(ProjectStatus::class)->findOneBy(['workspace' => $workspace]);
            if ($status === null) {
                throw new \RuntimeException('No ProjectStatus available for ' . ($p['name'] ?? '?'));
            }
        }

        $type = isset($p['projectTypeId']) ? ($projectTypeMap[$p['projectTypeId']] ?? null) : null;

        $owner = null;
        foreach ($p['members'] ?? [] as $m) {
            if (($m['isResponsible'] ?? false) === true && isset($userMap[$m['userId']])) {
                $owner = $userMap[$m['userId']];
                break;
            }
        }
        $owner ??= $userMap['eb0e5771-aa32-ec11-ae72-501ac527a4b8'] ?? null;

        $key = $p['projectKey'] ?? strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $p['name'] ?? 'P') ?? 'P', 0, 6));

        $project
            ->setWorkspace($workspace)
            ->setName($p['name'] ?? 'Untitled')
            ->setKey($key)
            ->setDescription(($p['description'] ?? '') === '' ? null : (string) $p['description'])
            ->setStatus($status)
            ->setProjectType($type)
            ->setOwner($owner)
            ->setIsPrivate((bool) ($p['isPrivate'] ?? false))
            ->setIsBillableByDefault((bool) ($p['isBillableByDefault'] ?? false))
            ->setDeductNonBillableHours((bool) ($p['deductNonBillableHours'] ?? false))
            ->setIsRetainer((bool) ($p['isRetainer'] ?? false))
            ->setIsMultiAssignmentAllowed((bool) ($p['isMultiAssignmentAllowed'] ?? true))
            ->setBudgetMinutes($this->secondsToMinutes($p['timeBudget'] ?? null))
            ->setIsArchived(false);

        $companyId = $p['companyId'] ?? null;
        if (\is_string($companyId) && isset($customerMap[$companyId])) {
            $project->setCustomer($customerMap[$companyId]);
        } else {
            $project->setCustomer(null);
        }

        $project->setExternalSource(self::EXTERNAL_SOURCE);
        $project->setExternalId(self::ref($awid));

        $this->em->persist($project);
        return $project;
    }

    /**
     * @param array<array<string,mixed>> $rows
     * @param array<string, User> $userMap
     */
    private function upsertProjectMembers(array $rows, Project $project, array $userMap): void
    {
        $repo = $this->em->getRepository(ProjectMember::class);
        foreach ($rows as $m) {
            $userId = $m['userId'] ?? null;
            if (!$userId || !isset($userMap[$userId])) {
                continue;
            }
            $user = $userMap[$userId];
            $existing = $repo->findOneBy(['project' => $project, 'user' => $user]);
            $role = ($m['isResponsible'] ?? false) === true
                ? ProjectMemberRole::Manager
                : ProjectMemberRole::Contributor;
            if ($existing === null) {
                $pm = (new ProjectMember())
                    ->setProject($project)
                    ->setUser($user)
                    ->setRole($role);
                $this->em->persist($pm);
            } else {
                $existing->setRole($role);
            }
        }
    }

    /**
     * @param array<string, mixed> $t
     * @param array<string, User> $userMap
     * @param array<string, TaskStatus> $taskStatusMap
     */
    private function upsertTask(
        array $t,
        Project $project,
        array $userMap,
        array $taskStatusMap,
    ): Task {
        $repo = $this->em->getRepository(Task::class);
        $awid = $t['id'];

        $task = $repo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => self::ref($awid)]);
        if ($task === null) {
            $task = new Task();
        }

        $statusId = $t['taskStatus']['id'] ?? $t['taskStatusId'] ?? null;
        $status = $statusId !== null ? ($taskStatusMap[$statusId] ?? null) : null;
        if ($status === null) {
            // fallback: any workspace status named like awork's
            $statusName = $t['taskStatus']['name'] ?? null;
            if ($statusName !== null) {
                $status = $this->em->getRepository(TaskStatus::class)->findOneBy([
                    'workspace' => $project->getWorkspace(),
                    'name' => $statusName,
                ]);
            }
        }
        if ($status === null) {
            throw new \RuntimeException('Task ' . $awid . ' references unknown taskStatus.');
        }

        $priority = match ($t['priority'] ?? null) {
            'low' => TaskPriority::Low,
            'high' => TaskPriority::High,
            'urgent' => TaskPriority::Urgent,
            default => TaskPriority::Normal,
        };

        $assignees = [];
        foreach (($t['assignedUsers'] ?? []) as $au) {
            $auId = $au['id'] ?? null;
            if ($auId !== null && isset($userMap[$auId])) {
                $assignees[] = $userMap[$auId];
            }
        }

        $task
            ->setWorkspace($project->getWorkspace())
            ->setProject($project)
            ->setIdentifier((string) ($t['taskIdentifier'] ?? ($project->getKey() . '-' . ($t['taskNumber'] ?? 0))))
            ->setTitle((string) ($t['name'] ?? 'Untitled'))
            ->setDescription($t['description'] ?? null)
            ->setStatus($status)
            ->setPriority($priority)
            ->setEstimatedMinutes($this->secondsToMinutes($t['plannedDuration'] ?? null))
            ->setAssignees($assignees)
            ->setIsPrio((bool) ($t['isPrio'] ?? false))
            ->setIsHiddenForConnectUsers((bool) ($t['isHiddenForConnectUsers'] ?? false))
            ->setDueOn($this->parseDate($t['dueOn'] ?? null))
            ->setStartedOn($this->parseDate($t['startOn'] ?? null));

        $closedOn = $this->parseDate($t['closedOn'] ?? null);
        $task->setClosedOn($closedOn);
        if ($closedOn !== null) {
            $task->setClosedBy(null);
        }

        $task->setExternalSource(self::EXTERNAL_SOURCE);
        $task->setExternalId(self::ref($awid));

        $this->em->persist($task);
        return $task;
    }

    /**
     * Replicate awork's time entries for one project into Worktide TimeEntry
     * rows. awork stores `startDateUtc` and `startTimeUtc` separately — we
     * join them into a single DateTimeImmutable in UTC. Duration is in seconds
     * and gets divided by 60 (rounded) into Worktide's minute granularity.
     *
     * Skips any row whose user is not in our map (= external user we chose
     * not to import). Idempotent via (externalSource='awork', externalId).
     *
     * @param array<array<string,mixed>> $rows
     * @param array<string, User> $userMap
     * @param array<string, TypeOfWork> $typeOfWorkCache
     * @return int  rows actually persisted (skipped users excluded)
     */
    private function importTimeEntries(
        array $rows,
        Project $project,
        array $userMap,
        array &$typeOfWorkCache,
    ): int {
        $repo = $this->em->getRepository(TimeEntry::class);
        $imported = 0;
        foreach ($rows as $r) {
            $awid = $r['id'] ?? null;
            $userAwId = $r['userId'] ?? ($r['user']['id'] ?? null);
            if (!\is_string($awid) || !\is_string($userAwId) || !isset($userMap[$userAwId])) {
                continue;
            }

            $entry = $repo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => self::ref($awid)]);
            if ($entry === null) {
                $entry = new TimeEntry();
            }

            $startsAt = $this->joinUtc($r['startDateUtc'] ?? null, $r['startTimeUtc'] ?? null);
            if ($startsAt === null) {
                continue;
            }
            $endsAt = $this->joinUtc($r['endDateUtc'] ?? null, $r['endTimeUtc'] ?? null);

            $duration = (int) ($r['duration'] ?? 0);
            $minutes = $duration > 0 ? (int) round($duration / 60) : 0;

            // typeOfWork inline on the entry — reuse the workspace cache.
            $tow = $this->resolveTypeOfWork($r, $project->getWorkspace(), $typeOfWorkCache);

            $entry
                ->setWorkspace($project->getWorkspace())
                ->setProject($project)
                ->setUser($userMap[$userAwId])
                ->setTypeOfWork($tow)
                ->setStartsAt($startsAt)
                ->setEndsAt($endsAt)
                ->setDurationMinutes($minutes)
                ->setNote(($r['note'] ?? '') !== '' ? (string) $r['note'] : null)
                ->setIsBillable((bool) ($r['isBillable'] ?? true))
                ->setIsBilled((bool) ($r['isBilled'] ?? false));

            $entry->setExternalSource(self::EXTERNAL_SOURCE);
            $entry->setExternalId(self::ref($awid));
            $this->em->persist($entry);
            $imported++;
        }
        return $imported;
    }

    /**
     * Combine awork's separate date+time UTC parts back into one DateTime.
     * Returns null when either part is missing or unparseable.
     */
    private function joinUtc(mixed $date, mixed $time): ?\DateTimeImmutable
    {
        if (!\is_string($date) || $date === '') {
            return null;
        }
        // awork's startDateUtc looks like "2025-04-03T00:00:00Z" — strip the
        // time half and graft on startTimeUtc ("08:40:00") so DST handling
        // doesn't shift the timestamp.
        $datePart = \substr($date, 0, 10);
        $timePart = \is_string($time) && $time !== '' ? $time : '00:00:00';
        try {
            return new \DateTimeImmutable($datePart . 'T' . $timePart . 'Z');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $t
     * @param array<string, TypeOfWork> $cache  awork TypeOfWork id → entity
     */
    private function resolveTypeOfWork(array $t, Workspace $workspace, array &$cache): ?TypeOfWork
    {
        $tow = $t['typeOfWork'] ?? null;
        if (!is_array($tow) || !isset($tow['id'], $tow['name'])) {
            return null;
        }
        $awid = $tow['id'];
        if (isset($cache[$awid])) {
            return $cache[$awid];
        }

        $repo = $this->em->getRepository(TypeOfWork::class);
        $existing = $repo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => self::ref($awid)])
            ?: $repo->findOneBy(['workspace' => $workspace, 'name' => $tow['name']]);

        if ($existing === null) {
            $existing = (new TypeOfWork())
                ->setWorkspace($workspace)
                ->setName((string) $tow['name'])
                ->setIcon($tow['icon'] ?? null)
                ->setIsArchived((bool) ($tow['isArchived'] ?? false))
                ->setIsBillableByDefault(true);
            $existing->setExternalSource(self::EXTERNAL_SOURCE);
            $existing->setExternalId(self::ref($awid));
            $this->em->persist($existing);
        }
        $cache[$awid] = $existing;
        return $existing;
    }

    private function secondsToMinutes(mixed $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }
        $s = (int) $seconds;
        if ($s <= 0) {
            return 0;
        }
        return (int) round($s / 60);
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function mapColor(?string $awColor, string $default): string
    {
        if (!is_string($awColor) || $awColor === '') {
            return $default;
        }
        return str_starts_with($awColor, '#') ? $awColor : "#$awColor";
    }
}
