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
use App\Entity\TaskList;
use App\Entity\TaskListEntry;
use App\Service\FileStorage;
use App\Entity\Enum\TagScope;
use App\Entity\Enum\TaskPriority;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectStatus;
use App\Entity\Tag;
use App\Entity\Task;
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
            ->setTimezone('Europe/Berlin');
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
                    ->setAssignees([$users[$assigneeIdx]])
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

        $om->flush();
    }
}
