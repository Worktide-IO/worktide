<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ChecklistItem;
use App\Entity\Comment;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\CustomFieldTarget;
use App\Entity\Enum\CustomFieldType;
use App\Entity\Enum\ProjectMemberRole;
use App\Entity\Enum\FileTarget;
use App\Entity\Enum\TaskDependencyType;
use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\ProjectMilestone;
use App\Entity\TaskDependency;
use App\Entity\Absence;
use App\Entity\Automation;
use App\Entity\AutomationAction;
use App\Entity\Autopilot;
use App\Entity\Document;
use App\Entity\DocumentContributor;
use App\Entity\DocumentSpace;
use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\CustomerSystem;
use App\Entity\Enum\BillingCycle;
use App\Entity\Enum\Capability;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\SubscriptionStatus;
use App\Entity\Enum\SystemEnvironment;
use App\Entity\Enum\SystemType;
use App\Entity\RolePermissionOverride;
use App\Entity\ServiceSubscription;
use App\Entity\Webhook;
use App\Entity\Enum\DocumentAccess;
use App\Entity\Enum\DocumentBodyFormat;
use App\Entity\TaskView;
use App\Entity\Enum\AutomationActionType;
use App\Entity\Enum\AutomationTriggerType;
use App\Entity\Team;
use App\Entity\TypeOfWork;
use App\Entity\UserCapacity;
use App\Entity\UserContactInfo;
use App\Entity\WorkspaceAbsence;
use App\Entity\ProjectTemplate;
use App\Entity\TaskBundle;
use App\Entity\TaskList;
use App\Entity\TaskListEntry;
use App\Entity\TaskSchedule;
use App\Entity\TaskTemplate;
use App\Entity\Workflow;
use App\Service\FileStorage;
use App\Entity\Enum\TagScope;
use App\Entity\Enum\TaskPriority;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectStatus;
use App\Entity\Tag;
use App\Entity\Task;
use App\Entity\TaskAssignee;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\TaskStatus;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly FileStorage $fileStorage,
    ) {}

    public function load(ObjectManager $om): void
    {
        // ---- Workspace ----------------------------------------------------
        $workspace = (new Workspace())
            ->setName('Wappler Systems')
            ->setSlug('wappler-systems')
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            // Customer portal enabled for the demo (see the portal block at the
            // end of load() — a ready-to-use portal contact login).
            ->setSettings(['portal' => ['enabled' => true]]);
        $om->persist($workspace);

        // ---- Users --------------------------------------------------------
        $users = [];
        $userData = [
            ['sven@worktide.test', 'Sven', 'Wappler', ['ROLE_ADMIN']],
            ['alex@worktide.test', 'Alex', 'Becker', []],
            ['mira@worktide.test', 'Mira', 'Hoffmann', []],
            ['tom@worktide.test', 'Tom', 'Schneider', []],
        ];
        foreach ($userData as $i => [$email, $first, $last, $roles]) {
            $user = (new User())
                ->setEmail($email)
                ->setFirstName($first)
                ->setLastName($last)
                ->setRoles($roles);
            $user->setPassword($this->hasher->hashPassword($user, 'demo'));
            $om->persist($user);
            $users[$i] = $user;

            $member = (new WorkspaceMember())
                ->setWorkspace($workspace)
                ->setUser($user)
                ->setRole($i === 0 ? WorkspaceMemberRole::Owner : WorkspaceMemberRole::Member);
            $om->persist($member);
        }

        // ---- Project Statuses ---------------------------------------------
        $projectStatuses = [];
        foreach ([
            ['Planung', '#a78bfa', 10, false, false],
            ['Aktiv', '#22c55e', 20, false, false],
            ['On Hold', '#facc15', 30, false, false],
            ['Abgeschlossen', '#64748b', 40, true, false],
        ] as $i => [$name, $color, $pos, $done, $archived]) {
            $st = (new ProjectStatus())
                ->setWorkspace($workspace)
                ->setName($name)
                ->setColor($color)
                ->setPosition($pos)
                ->setIsCompleted($done)
                ->setIsArchived($archived);
            $om->persist($st);
            $projectStatuses[$i] = $st;
        }

        // ---- Task Statuses ------------------------------------------------
        $taskStatuses = [];
        foreach ([
            ['Backlog', '#94a3b8', 10, false, true],
            ['In Arbeit', '#3b82f6', 20, false, false],
            ['Review', '#f59e0b', 30, false, false],
            ['Erledigt', '#22c55e', 40, true, false],
        ] as $i => [$name, $color, $pos, $done, $default]) {
            $ts = (new TaskStatus())
                ->setWorkspace($workspace)
                ->setName($name)
                ->setColor($color)
                ->setPosition($pos)
                ->setIsCompleted($done)
                ->setIsDefault($default);
            $om->persist($ts);
            $taskStatuses[$i] = $ts;
        }

        // ---- Custom Field Definitions ------------------------------------
        $cfDefs = [];
        $cfData = [
            ['project', 'budget_owner', 'Budget-Verantwortlicher', CustomFieldTarget::Project, CustomFieldType::User, []],
            ['project', 'priority_class', 'Priorisierung', CustomFieldTarget::Project, CustomFieldType::Select, ['A', 'B', 'C']],
            ['task', 'effort_estimate', 'Schätzung in Stunden', CustomFieldTarget::Task, CustomFieldType::Number, []],
            ['task', 'requires_review', 'Review nötig?', CustomFieldTarget::Task, CustomFieldType::Boolean, []],
            ['task', 'review_url', 'Review-Link', CustomFieldTarget::Task, CustomFieldType::Url, []],
        ];
        foreach ($cfData as $i => [$_, $key, $label, $target, $type, $options]) {
            $def = (new CustomFieldDefinition())
                ->setWorkspace($workspace)
                ->setTarget($target)
                ->setType($type)
                ->setKey($key)
                ->setLabel($label)
                ->setOptions($options)
                ->setPosition($i * 10);
            $om->persist($def);
            $cfDefs[$key] = $def;
        }

        // ---- Tags ---------------------------------------------------------
        $tags = [];
        foreach ([
            ['Frontend', '#ec4899', TagScope::Task],
            ['Backend', '#0ea5e9', TagScope::Task],
            ['Bug', '#ef4444', TagScope::Task],
            ['Kunde A', '#8b5cf6', TagScope::Project],
            ['Intern', '#10b981', TagScope::Any],
        ] as [$name, $color, $scope]) {
            $tag = (new Tag())
                ->setWorkspace($workspace)
                ->setName($name)
                ->setColor($color)
                ->setScope($scope);
            $om->persist($tag);
            $tags[$name] = $tag;
        }

        // ---- Projects + Tasks + Time Entries ------------------------------
        $now = new \DateTimeImmutable();

        $projectsData = [
            [
                'name' => 'Worktide Launch',
                'key' => 'WORK',
                'description' => 'Bootstrapping the Worktide product.',
                'color' => '#6366f1',
                'status' => 1,
                'owner' => 0,
                'budget' => 8000,
                'tags' => ['Intern'],
                'tasks' => [
                    ['MVP Skeleton aufsetzen', 1, 0, TaskPriority::High, ['Backend']],
                    ['Login-Form bauen', 2, 1, TaskPriority::Normal, ['Frontend']],
                    ['Time-Tracker UI prototypen', 0, 1, TaskPriority::Normal, ['Frontend']],
                    ['Permission-Modell entwerfen', 0, 0, TaskPriority::High, ['Backend']],
                ],
            ],
            [
                'name' => 'Kunde A — Migration',
                'key' => 'KA',
                'description' => 'TYPO3 v12 → v14 Upgrade.',
                'color' => '#f97316',
                'status' => 1,
                'owner' => 1,
                'budget' => 4800,
                'tags' => ['Kunde A'],
                'tasks' => [
                    ['Stage-DB klonen', 3, 1, TaskPriority::Normal, []],
                    ['Extension-Audit', 1, 1, TaskPriority::Normal, ['Backend']],
                    ['Migrations-Wizards laufen lassen', 0, 2, TaskPriority::Urgent, []],
                ],
            ],
            [
                'name' => 'Interner Wiki-Refresh',
                'key' => 'WIKI',
                'description' => 'MediaWiki-Doku aktualisieren.',
                'color' => '#14b8a6',
                'status' => 0,
                'owner' => 2,
                'budget' => null,
                'tags' => ['Intern'],
                'tasks' => [
                    ['Outdated Pages identifizieren', 0, 2, TaskPriority::Low, []],
                    ['Template-Vorlage erneuern', 0, 3, TaskPriority::Normal, ['Frontend']],
                ],
            ],
        ];

        /** @var array<string, Project> $createdProjects */
        $createdProjects = [];
        /** @var array<string, Task> $createdTasks */
        $createdTasks = [];

        foreach ($projectsData as $p) {
            $project = (new Project())
                ->setWorkspace($workspace)
                ->setName($p['name'])
                ->setKey($p['key'])
                ->setDescription($p['description'])
                ->setColor($p['color'])
                ->setStatus($projectStatuses[$p['status']])
                ->setOwner($users[$p['owner']])
                ->setStartsOn($now->modify('-30 days'))
                ->setDueOn($now->modify('+60 days'))
                ->setBudgetMinutes($p['budget']);
            foreach ($p['tags'] as $tagName) {
                $project->addTag($tags[$tagName]);
            }
            $om->persist($project);
            $createdProjects[$p['key']] = $project;

            $om->persist(
                (new ProjectMember())
                    ->setProject($project)
                    ->setUser($users[$p['owner']])
                    ->setRole(ProjectMemberRole::Manager)
            );
            $contributorIdx = ($p['owner'] + 1) % count($users);
            $om->persist(
                (new ProjectMember())
                    ->setProject($project)
                    ->setUser($users[$contributorIdx])
                    ->setRole(ProjectMemberRole::Contributor)
            );

            foreach ($p['tasks'] as $idx => [$title, $statusIdx, $assigneeIdx, $priority, $taskTagNames]) {
                $task = (new Task())
                    ->setWorkspace($workspace)
                    ->setProject($project)
                    ->setIdentifier(sprintf('%s-%d', $p['key'], $idx + 1))
                    ->setTitle($title)
                    ->setStatus($taskStatuses[$statusIdx])
                    ->setPriority($priority)
                    // Assignees are polymorphic (User|Team) since the
                    // TaskAssignee refactor; the old setAssignees([User])
                    // shortcut is gone. Build a User principal directly —
                    // $users are already persisted above, so getId() holds
                    // a UUID (Symfony's generator is pre-insert), and the
                    // cascade:['persist'] on assignedPrincipals saves it.
                    ->addAssignedPrincipal(
                        (new TaskAssignee())
                            ->setPrincipalType(AssigneePrincipalType::User)
                            ->setPrincipalId($users[$assigneeIdx]->getId())
                    )
                    ->setCreatedBy($users[$p['owner']])
                    ->setDueOn($now->modify('+' . (7 + $idx * 4) . ' days'))
                    ->setEstimatedMinutes(($idx + 1) * 60)
                    ->setPosition($idx);
                foreach ($taskTagNames as $tn) {
                    $task->addTag($tags[$tn]);
                }
                $om->persist($task);
                $createdTasks[sprintf('%s-%d', $p['key'], $idx + 1)] = $task;

                if ($p['key'] === 'WORK' && $idx < 3) {
                    for ($d = 1; $d <= 3; $d++) {
                        $start = $now->modify("-$d days")->setTime(9, 0);
                        $duration = 45 + ($idx * 30) + ($d * 15);
                        $end = $start->modify("+$duration minutes");
                        $te = (new TimeEntry())
                            ->setWorkspace($workspace)
                            ->setUser($users[$assigneeIdx])
                            ->setProject($project)
                            ->setTask($task)
                            ->setStartsAt($start)
                            ->setEndsAt($end)
                            ->setDurationMinutes($duration)
                            ->setNote("Arbeit an: $title")
                            ->setIsBillable(true);
                        $om->persist($te);
                    }
                }
            }
        }

        // Intermediate flush — UUIDs are populated by Doctrine's UuidGenerator
        // only at persist time, so comments can now reference task/project ids.
        $om->flush();

        // ---- Comments (B1) ------------------------------------------------
        $commentData = [
            // [target, key, author-idx, content, pinned?, isResolved?]
            [CommentTarget::Project, 'WORK', 0, "Kickoff war heute. Backlog für Sprint 1 steht.", true, false],
            [CommentTarget::Project, 'WORK', 1, "Architektur-Entscheidung: wir nehmen Worktide selbst als Dogfood-Plattform 🚀", false, false],
            [CommentTarget::Task, 'WORK-1', 1, "Skeleton steht — Symfony 8.1 + DDEV laufen. Nächster Schritt: Auth.", false, true],
            [CommentTarget::Task, 'WORK-2', 0, "Bitte mit Username + Passwort + Remember-Me bauen.", false, false],
            [CommentTarget::Task, 'WORK-4', 0, "Permissions: erstmal Voter, später granular per Role-Entity.", false, false],
            [CommentTarget::Project, 'KA', 1, "Kunde A meldet sich am Montag wegen Termin-Fenster.", true, false],
        ];

        // ---- TaskLists + Entries (B2) -------------------------------------
        $listColumns = [
            ['Backlog', '#94a3b8'],
            ['In Arbeit', '#3b82f6'],
            ['Review', '#f59e0b'],
            ['Erledigt', '#22c55e'],
        ];
        foreach ($createdProjects as $projectKey => $project) {
            $listsForProject = [];
            foreach ($listColumns as $i => [$name, $color]) {
                $list = (new TaskList())
                    ->setWorkspace($workspace)
                    ->setProject($project)
                    ->setName($name)
                    ->setColor($color)
                    ->setPosition((float) (($i + 1) * 10));
                $om->persist($list);
                $listsForProject[$name] = $list;
            }

            $positionPerList = ['Backlog' => 0, 'In Arbeit' => 0, 'Review' => 0, 'Erledigt' => 0];
            foreach ($createdTasks as $taskIdentifier => $task) {
                if (!str_starts_with($taskIdentifier, $projectKey . '-')) {
                    continue;
                }
                $statusName = $task->getStatus()->getName();
                $listName = match ($statusName) {
                    'Backlog', 'In Arbeit', 'Review', 'Erledigt' => $statusName,
                    default => 'Backlog',
                };
                $list = $listsForProject[$listName];
                $positionPerList[$listName] += 10;
                $entry = (new TaskListEntry())
                    ->setList($list)
                    ->setTask($task)
                    ->setPosition((float) $positionPerList[$listName]);
                $om->persist($entry);
            }
        }

        // ---- Checklist Items on WORK-1 ------------------------------------
        $work1 = $createdTasks['WORK-1'] ?? null;
        if ($work1 !== null) {
            $items = [
                ['DDEV-Projekt aufsetzen', true, $users[0]],
                ['Symfony 8.1 installieren', true, $users[0]],
                ['Doctrine + Migrations', true, $users[0]],
                ['Erste Entity (Workspace) anlegen', false, null],
                ['Fixtures + Smoke-Test', false, null],
            ];
            foreach ($items as $i => [$name, $done, $by]) {
                $item = (new ChecklistItem())
                    ->setWorkspace($workspace)
                    ->setTask($work1)
                    ->setName($name)
                    ->setPosition((float) (($i + 1) * 10));
                if ($done) {
                    $item->setIsDone(true);
                    if ($by !== null) {
                        $item->setCheckedBy($by);
                    }
                }
                $om->persist($item);
            }
        }

        // ---- TaskDependencies (B3) ---------------------------------------
        // WORK-1 (MVP Skeleton) → blocks WORK-2 (Login-Form)
        // WORK-2 (Login-Form) → blocks WORK-3 (Time-Tracker UI)
        // WORK-1 → blocks WORK-4 (Permission-Modell)
        $depPairs = [
            ['WORK-1', 'WORK-2', TaskDependencyType::FinishToStart, 0],
            ['WORK-2', 'WORK-3', TaskDependencyType::FinishToStart, 60],
            ['WORK-1', 'WORK-4', TaskDependencyType::FinishToStart, 0],
            ['KA-1', 'KA-2', TaskDependencyType::FinishToStart, 0],
        ];
        foreach ($depPairs as [$predKey, $succKey, $type, $lag]) {
            if (isset($createdTasks[$predKey], $createdTasks[$succKey])) {
                $dep = (new TaskDependency())
                    ->setWorkspace($workspace)
                    ->setPredecessor($createdTasks[$predKey])
                    ->setSuccessor($createdTasks[$succKey])
                    ->setType($type)
                    ->setLagMinutes($lag);
                $om->persist($dep);
            }
        }

        // ---- ProjectMilestones (B3) --------------------------------------
        $milestoneData = [
            ['WORK', 'MVP Release', '#a78bfa', '+30 days', 0, ['WORK-1', 'WORK-2'], false],
            ['WORK', 'Beta-Launch', '#facc15', '+60 days', 10, ['WORK-3', 'WORK-4'], false],
            ['KA', 'Go-Live', '#22c55e', '+45 days', 0, ['KA-3'], false],
        ];
        foreach ($milestoneData as [$projKey, $name, $color, $dueOffset, $position, $taskKeys, $isReached]) {
            if (!isset($createdProjects[$projKey])) {
                continue;
            }
            $milestone = (new ProjectMilestone())
                ->setWorkspace($workspace)
                ->setProject($createdProjects[$projKey])
                ->setName($name)
                ->setColor($color)
                ->setDueOn($now->modify($dueOffset))
                ->setPosition($position);
            foreach ($taskKeys as $tk) {
                if (isset($createdTasks[$tk])) {
                    $milestone->addTask($createdTasks[$tk]);
                }
            }
            if ($isReached) {
                $milestone->setIsReached(true);
                $milestone->setReachedBy($users[0]);
            }
            $om->persist($milestone);
        }

        // ---- Files (B4) — real bytes on Flysystem -----------------------
        // ---- Workforce (B7) ---------------------------------------------
        // Types of Work
        $typesOfWork = [];
        foreach ([
            ['Entwicklung',     '#3b82f6', true],
            ['Projektleitung',  '#22c55e', true],
            ['Meeting',         '#f59e0b', false],
            ['Recherche',       '#a78bfa', true],
        ] as [$name, $color, $billable]) {
            $tow = (new TypeOfWork())
                ->setWorkspace($workspace)
                ->setName($name)
                ->setColor($color)
                ->setIsBillableByDefault($billable);
            $om->persist($tow);
            $typesOfWork[$name] = $tow;
        }

        // Team
        $coreTeam = (new Team())
            ->setWorkspace($workspace)
            ->setName('Core-Team')
            ->setIcon('users')
            ->setColor('#6366f1')
            ->setDescription('Hauptteam für Worktide-Entwicklung');
        $coreTeam->addMember($users[0])->addMember($users[1])->addMember($users[2]);
        $coreTeam->addProject($createdProjects['WORK']);
        $om->persist($coreTeam);

        // Capacities (8h Mo-Fr für alle ausser Tom mit 6h)
        foreach ([
            [$users[0], 480, 480, 480, 480, 480, 0, 0],
            [$users[1], 480, 480, 480, 480, 480, 0, 0],
            [$users[2], 480, 480, 480, 480, 480, 0, 0],
            [$users[3], 360, 360, 360, 360, 360, 0, 0],
        ] as $row) {
            $user = array_shift($row);
            [$mo, $di, $mi, $do, $fr, $sa, $so] = $row;
            $cap = (new UserCapacity())
                ->setUser($user)
                ->setMonMinutes($mo)->setTueMinutes($di)->setWedMinutes($mi)
                ->setThuMinutes($do)->setFriMinutes($fr)->setSatMinutes($sa)->setSunMinutes($so);
            $om->persist($cap);
        }

        // Personal absence: Alex Urlaub
        $alexAbsence = (new Absence())
            ->setWorkspace($workspace)
            ->setUser($users[1])
            ->setStartsOn($now->modify('+14 days'))
            ->setEndsOn($now->modify('+21 days'))
            ->setType('vacation')
            ->setDescription('Sommerurlaub');
        $om->persist($alexAbsence);

        // Workspace absence: Betriebsausflug
        $companyDay = (new WorkspaceAbsence())
            ->setWorkspace($workspace)
            ->setName('Betriebsausflug')
            ->setStartsOn($now->modify('+30 days'))
            ->setEndsOn($now->modify('+30 days'))
            ->setDescription('Halbtags, danach gemeinsames Abendessen');
        $om->persist($companyDay);

        // Contact info for Sven
        $svenPhone = (new UserContactInfo())
            ->setUser($users[0])
            ->setType('phone')
            ->setSubType('mobile')
            ->setValue('+49 170 1234567')
            ->setLabel('Work mobile');
        $om->persist($svenPhone);

        // ---- Workflows + Automations (B6) --------------------------------
        // Extra "archiveable" tag for the demo automation to attach.
        $archiveTag = (new \App\Entity\Tag())
            ->setWorkspace($workspace)
            ->setName('archiveable')
            ->setColor('#475569')
            ->setScope(\App\Entity\Enum\TagScope::Task);
        $om->persist($archiveTag);
        $tags['archiveable'] = $archiveTag;

        $workflow = (new Workflow())
            ->setWorkspace($workspace)
            ->setName('Worktide-Standard-Workflow')
            ->setDescription('Auto-tag completed tasks for monthly cleanup.')
            ->setColor('#22c55e');
        $om->persist($workflow);

        $om->flush(); // workflow needs an ID before we reference it in automations + projects

        $erledigtStatus = $taskStatuses[3]; // "Erledigt" (isCompleted = true)

        $automation = (new Automation())
            ->setWorkspace($workspace)
            ->setWorkflow($workflow)
            ->setName('Tag erledigte Tasks als archiveable')
            ->setTriggerType(AutomationTriggerType::TaskStatusChanged)
            ->setTriggerConfig(['toStatusId' => $erledigtStatus->getId()->toRfc4122()])
            ->setPosition(10);
        $om->persist($automation);

        $action = (new AutomationAction())
            ->setAutomation($automation)
            ->setType(AutomationActionType::AddTaskTag)
            ->setConfig(['tagId' => $archiveTag->getId()->toRfc4122()])
            ->setPosition(10);
        $om->persist($action);

        // Hook WORK project to the workflow so its tasks trigger the automation.
        $createdProjects['WORK']->setWorkflow($workflow);

        // ---- TaskSchedule (B6) -------------------------------------------
        $schedule = (new TaskSchedule())
            ->setWorkspace($workspace)
            ->setProject($createdProjects['WORK'])
            ->setName('Wöchentlicher Standup')
            ->setCronExpression('0 9 * * 1') // Mondays 9am
            ->setTimezone('Europe/Berlin')
            ->setTaskTitle('Wöchentlicher Standup — Worktide')
            ->setTaskDescription('Was lief letzte Woche? Was steht an? Welche Blocker?')
            ->setTaskPriority('normal')
            ->setTaskEstimatedMinutes(30);
        $om->persist($schedule);

        // ---- Reporting + Autopilot (B8) ----------------------------------
        $work = $createdProjects['WORK'];

        // Demo TaskViews for Sven
        $om->persist((new TaskView())
            ->setWorkspace($workspace)
            ->setOwner($users[0])
            ->setName('Meine offenen Tasks')
            ->setIcon('user')
            ->setColor('#3b82f6')
            ->setFilter(['assignees' => '/v1/users/' . $users[0]->getId()?->toRfc4122(), 'exists[closedOn]' => 'false'])
            ->setSortOrder(['dueOn' => 'asc']));

        $om->persist((new TaskView())
            ->setWorkspace($workspace)
            ->setOwner($users[0])
            ->setName('Diese Woche fällig')
            ->setIcon('clock')
            ->setColor('#f59e0b')
            ->setFilter(['dueOn[before]' => $now->modify('+7 days')->format('Y-m-d')])
            ->setSortOrder(['priority' => 'desc', 'dueOn' => 'asc'])
            ->setIsShared(true));

        // Autopilot on WORK with 3 rules
        $om->persist((new Autopilot())
            ->setWorkspace($workspace)
            ->setProject($work)
            ->setRules([
                ['kind' => 'budget_threshold',  'config' => ['percent' => 50], 'isEnabled' => true],
                ['kind' => 'overdue_tasks',     'config' => ['gracePeriodDays' => 0], 'isEnabled' => true],
                ['kind' => 'due_soon',          'config' => ['withinDays' => 14], 'isEnabled' => true],
            ]));

        // ---- Documents (B9) ----------------------------------------------
        $knowledgeSpace = (new DocumentSpace())
            ->setWorkspace($workspace)
            ->setName('Knowledge Base')
            ->setDescription('Allgemeines Wissen: Onboarding, Prozesse, Tools.')
            ->setColor('#6366f1')
            ->setEmoji('📚')
            ->setPosition(10);
        $om->persist($knowledgeSpace);

        $projectSpace = (new DocumentSpace())
            ->setWorkspace($workspace)
            ->setName('Projekt-Dokumentation')
            ->setDescription('Pro Projekt: Briefings, Architektur-Entscheidungen, Postmortems.')
            ->setColor('#f59e0b')
            ->setEmoji('📐')
            ->setPosition(20);
        $om->persist($projectSpace);

        $onboarding = (new Document())
            ->setWorkspace($workspace)
            ->setSpace($knowledgeSpace)
            ->setName('Onboarding-Guide')
            ->setEmoji('🚀')
            ->setBodyFormat(DocumentBodyFormat::Markdown)
            ->setBody(<<<MD
# Willkommen bei Worktide

Dieser Guide fasst die wichtigsten Tools und Abläufe für neue Team-Mitglieder zusammen.

## Erste Schritte
1. Zugänge anfragen (Slack, GitLab, Lexoffice)
2. Workspace-Tour mit dem Onboarding-Buddy
3. Erste Tasks in `Onboarding`-Projekt durchgehen

## Wichtige Links
- Projekt-Übersicht im Workspace
- Wiki / Knowledge Base
- Time-Tracking-Richtlinien
MD)
            ->setPosition(10);
        $om->persist($onboarding);

        $worktideArchDoc = (new Document())
            ->setWorkspace($workspace)
            ->setSpace($projectSpace)
            ->setProject($work)
            ->setName('Worktide — Architektur-Entscheidungen')
            ->setEmoji('🏛️')
            ->setBodyFormat(DocumentBodyFormat::Markdown)
            ->setBody(<<<MD
# Architektur-Entscheidungen

## ADR-001: UUIDv7 als Primärschlüssel
Wir verwenden UUIDv7 statt Auto-Increment-IDs, weil…

## ADR-002: API Platform auf Subdomain
`api.worktide.ddev.site/v1` — entkoppelt Frontend-Routing vom API-Routing.

## ADR-003: Multi-Tenancy via Workspace
Jede tenant-scoped Entity bekommt `WorkspaceScopedTrait`. Voter prüfen Workspace-Mitgliedschaft.
MD)
            ->setPosition(10);
        $om->persist($worktideArchDoc);

        $privateScratch = (new Document())
            ->setWorkspace($workspace)
            ->setName('Sven — Private Notes')
            ->setEmoji('🔒')
            ->setIsPrivate(true)
            ->setBodyFormat(DocumentBodyFormat::Markdown)
            ->setBody("Private Scratch-Notes — nur für Sven sichtbar.")
            ->setPosition(99);
        $om->persist($privateScratch);

        // Document with a contributor sharing
        $sharedNote = (new Document())
            ->setWorkspace($workspace)
            ->setSpace($knowledgeSpace)
            ->setName('Geteilte Notizen — Recherche')
            ->setEmoji('📝')
            ->setIsPrivate(true)
            ->setBodyFormat(DocumentBodyFormat::Markdown)
            ->setBody('Privat, aber explizit mit dem zweiten User als Manager geteilt.')
            ->setPosition(50);
        $om->persist($sharedNote);

        if (isset($users[1])) {
            $om->persist((new DocumentContributor())
                ->setDocument($sharedNote)
                ->setUser($users[1])
                ->setAccess(DocumentAccess::Manage));
        }

        // ---- Webhooks (B10) ----------------------------------------------
        $om->persist((new Webhook())
            ->setWorkspace($workspace)
            ->setName('Demo Echo Sink')
            ->setUrl('https://api.worktide.ddev.site/v1/_webhook-echo')
            ->setSecret('demo-secret-' . bin2hex(random_bytes(8)))
            ->setEventTypes(['task.*', 'project.*'])
            ->setIsActive(true));

        // ---- CRM (Phase 3 — Block 1) -------------------------------------
        $acmeCustomer = (new Customer())
            ->setWorkspace($workspace)
            ->setName('Acme GmbH')
            ->setLegalName('Acme Software GmbH & Co. KG')
            ->setIsCompany(true)
            ->setVatId('DE123456789')
            ->setEmail('info@acme-software.example')
            ->setPhone('+49 30 1234567')
            ->setWebsite('https://acme-software.example')
            ->setAddressLine1('Friedrichstraße 12')
            ->setZip('10117')
            ->setCity('Berlin')
            ->setCountry('DE')
            ->setStatus(CustomerStatus::Active)
            ->setAccountManager($users[0])
            ->setNotes('Long-running customer — TYPO3 + WordPress projects, monthly retainer.');
        $om->persist($acmeCustomer);

        $globexCustomer = (new Customer())
            ->setWorkspace($workspace)
            ->setName('Globex Corp')
            ->setLegalName('Globex Corporation Ltd.')
            ->setIsCompany(true)
            ->setEmail('hello@globex.example')
            ->setWebsite('https://globex.example')
            ->setStatus(CustomerStatus::Prospect)
            ->setAccountManager($users[1] ?? $users[0])
            ->setNotes('Pitched in Q2, awaiting decision.');
        $om->persist($globexCustomer);

        $personalCustomer = (new Customer())
            ->setWorkspace($workspace)
            ->setName('Müller, Anna')
            ->setIsCompany(false)
            ->setEmail('anna@example.com')
            ->setCity('München')
            ->setCountry('DE')
            ->setStatus(CustomerStatus::Inactive)
            ->setNotes('Privatkundin — Einzel-Website 2023, abgeschlossen.');
        $om->persist($personalCustomer);

        $primary = (new Contact())
            ->setCustomer($acmeCustomer)
            ->setSalutation('Mr')
            ->setFirstName('Tobias')
            ->setLastName('Schmidt')
            ->setTitle('Dr.')
            ->setPosition('CTO')
            ->setEmail('t.schmidt@acme-software.example')
            ->setPhone('+49 30 1234567-21')
            ->setMobile('+49 171 1234567')
            ->setIsPrimary(true);
        $om->persist($primary);

        $om->persist((new Contact())
            ->setCustomer($acmeCustomer)
            ->setSalutation('Ms')
            ->setFirstName('Lisa')
            ->setLastName('Wagner')
            ->setPosition('Marketing Manager')
            ->setEmail('l.wagner@acme-software.example')
            ->setPhone('+49 30 1234567-15'));

        $om->persist((new Contact())
            ->setCustomer($globexCustomer)
            ->setFirstName('Hans')
            ->setLastName('Becker')
            ->setPosition('Head of Digital')
            ->setEmail('hans.becker@globex.example')
            ->setIsPrimary(true));

        // Link the demo Project to the Acme customer so reports + portal logic
        // have at least one customer-linked project to chew on.
        $work->setCustomer($acmeCustomer);

        // ---- CRM-2: CustomerSystems + ServiceSubscriptions ---------------
        $acmeTypo3 = (new CustomerSystem())
            ->setCustomer($acmeCustomer)
            ->setName('Acme Hauptseite')
            ->setType(SystemType::TYPO3)
            ->setSystemVersion('13.4')
            ->setUrl('https://www.acme-software.example')
            ->setStagingUrl('https://stage.acme-software.example')
            ->setAdminLoginUrl('https://www.acme-software.example/typo3/')
            ->setHostingProvider('Hetzner Cloud')
            ->setEnvironment(SystemEnvironment::Production)
            ->setNotes('Hauptpräsenz, Marketing-Site mit news + ke_search Suchindex.');
        $om->persist($acmeTypo3);

        $acmeWordpress = (new CustomerSystem())
            ->setCustomer($acmeCustomer)
            ->setName('Acme Blog')
            ->setType(SystemType::WordPress)
            ->setSystemVersion('6.6')
            ->setUrl('https://blog.acme-software.example')
            ->setHostingProvider('Hetzner Cloud')
            ->setEnvironment(SystemEnvironment::Production);
        $om->persist($acmeWordpress);

        $globexShop = (new CustomerSystem())
            ->setCustomer($globexCustomer)
            ->setName('Globex Online-Shop')
            ->setType(SystemType::Shopware)
            ->setSystemVersion('6.6')
            ->setUrl('https://shop.globex.example')
            ->setHostingProvider('AWS Frankfurt')
            ->setEnvironment(SystemEnvironment::Production)
            ->setIsActive(false)
            ->setNotes('Noch im Pitch — Shop ist beim aktuellen Anbieter, Übernahme bei Auftrag.');
        $om->persist($globexShop);

        // System-bound: monthly TYPO3 hosting + maintenance
        $om->persist((new ServiceSubscription())
            ->setCustomer($acmeCustomer)
            ->setSystem($acmeTypo3)
            ->setName('TYPO3 Hosting + Maintenance')
            ->setDescription('Hetzner Cloud CPX21, Backups, TYPO3 Updates, SSL.')
            ->setPriceCents(28000)
            ->setCurrency('eur')
            ->setBillingCycle(BillingCycle::Monthly)
            ->setStatus(SubscriptionStatus::Active)
            ->setStartedOn(new \DateTimeImmutable('2024-09-01'))
            ->setAutoRenew(true));

        // System-bound: yearly WordPress security pack
        $om->persist((new ServiceSubscription())
            ->setCustomer($acmeCustomer)
            ->setSystem($acmeWordpress)
            ->setName('WordPress Security Pack')
            ->setDescription('Wordfence Premium + monthly security audits.')
            ->setPriceCents(120000)
            ->setCurrency('eur')
            ->setBillingCycle(BillingCycle::Yearly)
            ->setStatus(SubscriptionStatus::Active)
            ->setStartedOn(new \DateTimeImmutable('2025-01-15'))
            ->setAutoRenew(true));

        // Customer-wide retainer (no system FK)
        $om->persist((new ServiceSubscription())
            ->setCustomer($acmeCustomer)
            ->setName('Premium Retainer 10h')
            ->setDescription('10 monthly support hours, carry-over for 1 quarter.')
            ->setPriceCents(95000)
            ->setCurrency('eur')
            ->setBillingCycle(BillingCycle::Monthly)
            ->setStatus(SubscriptionStatus::Active)
            ->setStartedOn(new \DateTimeImmutable('2024-06-01'))
            ->setAutoRenew(true));

        // One-off setup fee from a year ago (already billed, kept for history)
        $om->persist((new ServiceSubscription())
            ->setCustomer($acmeCustomer)
            ->setSystem($acmeTypo3)
            ->setName('Initial setup')
            ->setDescription('Migration TYPO3 v11→v13 inkl. Datenmodell-Cleanup.')
            ->setPriceCents(450000)
            ->setCurrency('eur')
            ->setBillingCycle(BillingCycle::Once)
            ->setStatus(SubscriptionStatus::Cancelled)
            ->setStartedOn(new \DateTimeImmutable('2024-08-01'))
            ->setEndedOn(new \DateTimeImmutable('2024-08-31'))
            ->setAutoRenew(false));

        // Trial subscription for Globex
        $om->persist((new ServiceSubscription())
            ->setCustomer($globexCustomer)
            ->setSystem($globexShop)
            ->setName('Hosting Migration — Trial')
            ->setDescription('30-day trial, then transition to standard hosting.')
            ->setPriceCents(0)
            ->setCurrency('eur')
            ->setBillingCycle(BillingCycle::Monthly)
            ->setStatus(SubscriptionStatus::Trial)
            ->setStartedOn(new \DateTimeImmutable('-3 days'))
            ->setAutoRenew(false));

        // ---- Permission overrides (B11) ----------------------------------
        // Tighten the default Member role for the demo workspace: they may
        // create and edit projects but not delete them, and they cannot
        // touch other users' tasks even when they otherwise could edit them.
        $om->persist((new RolePermissionOverride())
            ->setWorkspace($workspace)
            ->setRole(WorkspaceMemberRole::Member)
            ->setCapability(Capability::ProjectDelete)
            ->setIsGranted(false));
        $om->persist((new RolePermissionOverride())
            ->setWorkspace($workspace)
            ->setRole(WorkspaceMemberRole::Member)
            ->setCapability(Capability::TaskDeleteOthers)
            ->setIsGranted(false));

        // ---- Templates (B5) ----------------------------------------------
        $standardBundle = (new TaskBundle())
            ->setWorkspace($workspace)
            ->setName('Standard-Webprojekt')
            ->setColor('#6366f1')
            ->setDescription('Wiederverwendbare Tasks für ein Standard-Webentwicklungs-Projekt.');
        $om->persist($standardBundle);

        $bundleTasks = [
            ['Kickoff-Meeting',          TaskPriority::High,    60,  1,  ['Termin finden', 'Agenda verschicken']],
            ['Anforderungs-Workshop',    TaskPriority::High,    240, 3,  []],
            ['Designs erstellen',        TaskPriority::Normal,  480, 14, []],
            ['Entwicklung Sprint 1',     TaskPriority::Normal,  1200, 28, []],
            ['Testing + QA',             TaskPriority::Normal,  300, 42, []],
            ['Launch + Übergabe',        TaskPriority::Urgent,  120, 49, ['DNS-Switch', 'Monitoring scharf']],
        ];
        $bundlePosition = 0;
        foreach ($bundleTasks as [$title, $prio, $est, $dayOffset, $checklist]) {
            $bundlePosition += 10;
            $tpl = (new TaskTemplate())
                ->setWorkspace($workspace)
                ->setBundle($standardBundle)
                ->setTitle($title)
                ->setPriority($prio)
                ->setEstimatedMinutes($est)
                ->setDueDayOffset($dayOffset)
                ->setPosition($bundlePosition)
                ->setDefaultChecklist($checklist);
            $om->persist($tpl);
        }

        $projectTemplate = (new ProjectTemplate())
            ->setWorkspace($workspace)
            ->setName('Webprojekt — Standard')
            ->setDescription('Standardisierter Ablauf für mittlere Webprojekte mit Kickoff, Workshop, Entwicklung und Launch.')
            ->setColor('#6366f1')
            ->setTaskBundle($standardBundle)
            ->setDefaultBudgetMinutes(2400)
            ->setDefaultIsBillableByDefault(true);
        $om->persist($projectTemplate);

        $om->flush(); // ensure UUIDs exist for files referencing tasks/projects

        $fileSpecs = [
            [
                'target' => FileTarget::Project,
                'targetEntity' => $createdProjects['WORK'],
                'displayName' => 'Worktide-Pitch.md',
                'originalFilename' => 'pitch.md',
                'mimeType' => 'text/markdown',
                'bytes' => "# Worktide\n\nProject + Task + Time + CRM, alles in einem.\n\n## Phase 1\n- Workspace + Projects + Tasks\n- Time tracking\n- Comments + Activities\n",
                'uploadedBy' => 0,
            ],
            [
                'target' => FileTarget::Task,
                'targetEntity' => $createdTasks['WORK-1'],
                'displayName' => 'Schema.txt',
                'originalFilename' => 'schema.txt',
                'mimeType' => 'text/plain',
                'bytes' => "Worktide Phase-1 Schema\n=========================\n\nWorkspace --< Project --< Task --< TimeEntry\n        \\--< WorkspaceMember\nProject --< ProjectMember\n",
                'uploadedBy' => 1,
            ],
            [
                'target' => FileTarget::Workspace,
                'targetEntity' => $workspace,
                'displayName' => 'README.txt',
                'originalFilename' => 'readme.txt',
                'mimeType' => 'text/plain',
                'bytes' => "Wappler Systems Workspace\nLast seeded: " . $now->format('c') . "\n",
                'uploadedBy' => 0,
            ],
        ];

        foreach ($fileSpecs as $spec) {
            $file = (new File())
                ->setWorkspace($workspace)
                ->setTarget($spec['target'])
                ->setTargetId($spec['targetEntity']->getId())
                ->setName($spec['displayName'])
                ->setMimeType($spec['mimeType'])
                ->setUploadedBy($users[$spec['uploadedBy']]);
            $om->persist($file);
            $om->flush(); // get File UUID

            $version = (new FileVersion())
                ->setFile($file)
                ->setVersionNumber(1)
                ->setOriginalFilename($spec['originalFilename'])
                ->setMimeType($spec['mimeType'])
                ->setChecksum('pending')
                ->setStoragePath('pending')
                ->setUploadedBy($users[$spec['uploadedBy']]);
            $om->persist($version);
            $om->flush(); // get version UUID

            $info = $this->fileStorage->writeBytes(
                $spec['bytes'],
                $workspace,
                $file->getId(),
                $version->getId(),
                $spec['originalFilename'],
            );

            $version
                ->setSize($info['size'])
                ->setChecksum($info['checksum'])
                ->setStoragePath($info['path']);

            $file->setCurrentVersion($version);
        }

        foreach ($commentData as [$targetType, $key, $authorIdx, $content, $pinned, $resolved]) {
            $targetEntity = $targetType === CommentTarget::Project
                ? $createdProjects[$key]
                : $createdTasks[$key];

            $comment = (new Comment())
                ->setWorkspace($workspace)
                ->setTarget($targetType)
                ->setTargetId($targetEntity->getId())
                ->setAuthor($users[$authorIdx])
                ->setContent($content)
                ->setIsResolved($resolved);
            if ($pinned) {
                $comment->pin($users[0]);
            }
            $om->persist($comment);
        }

        // ---- Customer portal demo -----------------------------------------
        // A ready-to-use portal login so others can develop the portal against
        // real data. Contact "Lena Brandt" logs into the customer portal with
        // kontakt@nordlicht-medien.example / "portal-demo" and sees the external
        // project's non-hidden tickets. The hidden task/comment prove the
        // isHiddenForConnectUsers gating.
        $portalCustomer = (new Customer())
            ->setWorkspace($workspace)
            ->setName('Nordlicht Medien GmbH')
            ->setIsCompany(true)
            ->setEmail('info@nordlicht-medien.example')
            ->setStatus(CustomerStatus::Active)
            ->setAccountManager($users[0])
            ->setPortalEnabled(true) // Freigeschaltet, damit der Demo-Portal-Contact sich einloggen kann.
            ->setNotes('Demo-Kunde für das Kundenportal.');
        $om->persist($portalCustomer);

        $portalProject = (new Project())
            ->setWorkspace($workspace)
            ->setName('Website-Betreuung')
            ->setKey('PORT')
            ->setDescription('Laufende Pflege der Unternehmenswebsite.')
            ->setColor('#0ea5e9')
            ->setStatus($projectStatuses[1])
            ->setOwner($users[0])
            ->setCustomer($portalCustomer)
            ->setIsExternal(true); // visible to the customer portal
        $om->persist($portalProject);

        $portalTasks = [
            ['PORT-1', 'Startseite: neues Hero-Bild einpflegen', 1, false],
            ['PORT-2', 'Kontaktformular: DSGVO-Hinweis ergänzen', 0, false],
            ['PORT-3', 'Interner Hinweis: Server-Migration planen', 1, true], // hidden from portal
        ];
        $portalTaskByKey = [];
        foreach ($portalTasks as [$ident, $title, $statusIdx, $hidden]) {
            $t = (new Task())
                ->setWorkspace($workspace)
                ->setProject($portalProject)
                ->setIdentifier($ident)
                ->setTitle($title)
                ->setStatus($taskStatuses[$statusIdx])
                ->setCreatedVia(\App\Entity\Enum\TaskCreatedVia::Portal)
                ->setIsHiddenForConnectUsers($hidden);
            $om->persist($t);
            $portalTaskByKey[$ident] = $t;
        }

        $portalUser = (new User())
            ->setEmail('kontakt@nordlicht-medien.example')
            ->setFirstName('Lena')
            ->setLastName('Brandt')
            ->setRoles(['ROLE_PORTAL']);
        $portalUser->setPassword($this->hasher->hashPassword($portalUser, 'portal-demo'));
        $om->persist($portalUser);

        $portalContact = (new Contact())
            ->setCustomer($portalCustomer)
            ->setFirstName('Lena')
            ->setLastName('Brandt')
            ->setEmail('kontakt@nordlicht-medien.example')
            ->setIsActive(true)
            ->setLinkedUser($portalUser);
        $portalContact->setWorkspace($workspace);
        $om->persist($portalContact);

        // Public thread on PORT-1 (visible to the portal) + one internal note (hidden).
        foreach ([
            [$users[0], 'Wir haben ein paar Bildvorschläge vorbereitet — schauen Sie gern rein.', false],
            [$portalUser, 'Danke! Variante 2 gefällt uns am besten.', false],
            [$users[0], 'Intern: Lizenz des Stockfotos noch prüfen.', true],
        ] as [$author, $content, $hidden]) {
            $c = (new Comment())
                ->setTarget(CommentTarget::Task)
                ->setTargetId($portalTaskByKey['PORT-1']->getId())
                ->setAuthor($author)
                ->setContent($content)
                ->setIsResolved(false)
                ->setIsHiddenForConnectUsers($hidden);
            $c->setWorkspace($workspace);
            $om->persist($c);
        }

        $om->flush();
    }
}
