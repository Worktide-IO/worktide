<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Enum\ProjectMemberRole;
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
                    ->setAssignee($users[$assigneeIdx])
                    ->setCreatedBy($users[$p['owner']])
                    ->setDueOn($now->modify('+' . (7 + $idx * 4) . ' days'))
                    ->setEstimatedMinutes(($idx + 1) * 60)
                    ->setPosition($idx);
                foreach ($taskTagNames as $tn) {
                    $task->addTag($tags[$tn]);
                }
                $om->persist($task);

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

        $om->flush();
    }
}
