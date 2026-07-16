<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\TaskStatus;
use App\Entity\Tracker;
use App\Entity\Workspace;
use App\Service\Feedback\FeedbackProjectLocator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotently provisions the shared, cross-tenant "Feedback & Requests" board
 * (see docs/feedback-board-plan.md): a dedicated Worktide-owned platform
 * workspace, one feedback project (key WTFB), its issue-type trackers
 * (Bug / Feature / UI-UX) and its progress statuses.
 *
 * Runs via the ORM (so every entity default/trait is handled) and is safe to
 * re-run — each piece is created only when missing. Wired into the docker
 * entrypoint right after migrations, and runnable by hand:
 *
 *   bin/console app:feedback:bootstrap
 */
#[AsCommand(
    name: 'app:feedback:bootstrap',
    description: 'Provision the shared feedback board (platform workspace + WTFB project + trackers + statuses).',
)]
final class FeedbackBootstrapCommand extends Command
{
    // English fallback names + a stable `key` the anonymizer maps to i18n
    // labels (label.feedback.category.* / .status.*) so the cross-tenant board
    // renders in each viewer's own language regardless of these seed values.
    /** @var list<array{key: string, name: string, icon: string, color: string, default: bool}> */
    private const TRACKERS = [
        ['key' => 'bug', 'name' => 'Bug', 'icon' => 'bug', 'color' => '#ef4444', 'default' => true],
        ['key' => 'feature', 'name' => 'Feature request', 'icon' => 'sparkles', 'color' => '#6366f1', 'default' => false],
        ['key' => 'ui_ux', 'name' => 'UI/UX change', 'icon' => 'layout', 'color' => '#0ea5e9', 'default' => false],
    ];

    /** @var list<array{key: string, name: string, position: int, default: bool, completed: bool}> */
    private const STATUSES = [
        ['key' => 'new', 'name' => 'New', 'position' => 1, 'default' => true, 'completed' => false],
        ['key' => 'triaged', 'name' => 'Triaged', 'position' => 2, 'default' => false, 'completed' => false],
        ['key' => 'planned', 'name' => 'Planned', 'position' => 3, 'default' => false, 'completed' => false],
        ['key' => 'in_progress', 'name' => 'In progress', 'position' => 4, 'default' => false, 'completed' => false],
        ['key' => 'done', 'name' => 'Done', 'position' => 5, 'default' => false, 'completed' => true],
        ['key' => 'declined', 'name' => 'Declined', 'position' => 6, 'default' => false, 'completed' => true],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workspace = $this->ensureWorkspace();
        $projectStatus = $this->ensureProjectStatus($workspace);
        $statuses = $this->ensureTaskStatuses($workspace);
        $this->ensureTrackers($workspace, $statuses['New'] ?? null);
        $this->ensureProject($workspace, $projectStatus);

        $this->em->flush();

        $io->success('Feedback board is provisioned (workspace "worktide-platform", project WTFB).');

        return Command::SUCCESS;
    }

    private function ensureWorkspace(): Workspace
    {
        $repo = $this->em->getRepository(Workspace::class);
        $ws = $repo->findOneBy(['slug' => FeedbackProjectLocator::PLATFORM_WORKSPACE_SLUG]);
        if ($ws instanceof Workspace) {
            return $ws;
        }

        $ws = (new Workspace())
            ->setName('Worktide')
            ->setSlug(FeedbackProjectLocator::PLATFORM_WORKSPACE_SLUG)
            ->setSettings(['platform' => ['feedback' => true]]);
        $this->em->persist($ws);
        $this->em->flush();

        return $ws;
    }

    private function ensureProjectStatus(Workspace $workspace): ProjectStatus
    {
        $repo = $this->em->getRepository(ProjectStatus::class);
        $status = $repo->findOneBy(['workspace' => $workspace, 'name' => 'Aktiv']);
        if ($status instanceof ProjectStatus) {
            return $status;
        }

        $status = (new ProjectStatus())->setName('Aktiv')->setPosition(1);
        $status->setWorkspace($workspace);
        $this->em->persist($status);
        $this->em->flush();

        return $status;
    }

    /**
     * @return array<string, TaskStatus> keyed by status name
     */
    private function ensureTaskStatuses(Workspace $workspace): array
    {
        $repo = $this->em->getRepository(TaskStatus::class);
        $result = [];
        foreach (self::STATUSES as $def) {
            $status = $repo->findOneBy(['workspace' => $workspace, 'name' => $def['name']]);
            if (!$status instanceof TaskStatus) {
                $status = (new TaskStatus())
                    ->setName($def['name'])
                    ->setPosition($def['position'])
                    ->setIsDefault($def['default'])
                    ->setIsCompleted($def['completed']);
                $status->setWorkspace($workspace);
                $this->em->persist($status);
            }
            $result[$def['name']] = $status;
        }

        return $result;
    }

    private function ensureTrackers(Workspace $workspace, ?TaskStatus $defaultStatus): void
    {
        $repo = $this->em->getRepository(Tracker::class);
        foreach (self::TRACKERS as $def) {
            if ($repo->findOneBy(['workspace' => $workspace, 'name' => $def['name']]) instanceof Tracker) {
                continue;
            }
            $tracker = (new Tracker())
                ->setName($def['name'])
                ->setIcon($def['icon'])
                ->setColor($def['color'])
                ->setIsDefault($def['default']);
            if ($defaultStatus !== null) {
                $tracker->setDefaultStatus($defaultStatus);
            }
            $tracker->setWorkspace($workspace);
            $this->em->persist($tracker);
        }
    }

    private function ensureProject(Workspace $workspace, ProjectStatus $status): Project
    {
        $repo = $this->em->getRepository(Project::class);
        $project = $repo->findOneBy(['workspace' => $workspace, 'key' => FeedbackProjectLocator::FEEDBACK_PROJECT_KEY]);
        if ($project instanceof Project) {
            return $project;
        }

        $project = (new Project())
            ->setName('Produkt-Feedback')
            ->setKey(FeedbackProjectLocator::FEEDBACK_PROJECT_KEY)
            ->setDescription('Zentrales, tenant-übergreifendes Feedback-Board (Bugs, Feature-Wünsche, UI/UX).')
            ->setStatus($status)
            // Load-bearing: NOT external → never appears in any customer's
            // allowedProjects(), so portal clients can't reach feedback tasks
            // through the normal /v1/portal/tickets endpoints.
            ->setIsExternal(false)
            ->setIsProjectKeyVisible(true);
        $project->setWorkspace($workspace);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
