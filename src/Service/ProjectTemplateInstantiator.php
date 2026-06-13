<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ChecklistItem;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\ProjectTemplate;
use App\Entity\Task;
use App\Entity\TaskBundle;
use App\Entity\TaskStatus;
use App\Entity\TaskTemplate;
use App\Entity\User;
use App\Repository\ProjectStatusRepository;
use App\Repository\TaskStatusRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the instantiate-from-template and save-as-template flows. Kept as a
 * service (not inline in controllers) so both the public endpoints AND
 * future scheduled-jobs / import-tools can reuse the same logic.
 */
final class ProjectTemplateInstantiator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectStatusRepository $projectStatuses,
        private readonly TaskStatusRepository $taskStatuses,
    ) {}

    /**
     * Materialise a ProjectTemplate into a real Project + tasks.
     *
     * Returns the new Project together with the count of tasks created —
     * `$project->getTasks()->count()` lies after this call because the
     * lazy inverse-side collection isn't refreshed when tasks are persisted
     * directly via the EntityManager.
     *
     * @param array{name: string, projectKey: string, startsOn?: \DateTimeImmutable|null, owner?: User|null} $data
     * @return array{project: Project, tasksCreated: int}
     */
    public function instantiate(ProjectTemplate $template, array $data, User $actor): array
    {
        $workspace = $template->getWorkspace();
        $startsOn = $data['startsOn'] ?? null;

        $defaultProjectStatus = $this->resolveProjectStatus($workspace);
        if ($defaultProjectStatus === null) {
            throw new \RuntimeException('Workspace has no project status to assign — create at least one.');
        }

        $project = (new Project())
            ->setWorkspace($workspace)
            ->setName($data['name'])
            ->setKey($data['projectKey'])
            ->setDescription($template->getDescription())
            ->setColor($template->getColor())
            ->setProjectType($template->getProjectType())
            ->setStatus($defaultProjectStatus)
            ->setOwner($data['owner'] ?? $actor)
            ->setStartsOn($startsOn)
            ->setBudgetMinutes($template->getDefaultBudgetMinutes())
            ->setIsBillableByDefault($template->isDefaultIsBillableByDefault())
            ->setDeductNonBillableHours($template->isDefaultDeductNonBillableHours())
            ->setIsMultiAssignmentAllowed($template->isDefaultIsMultiAssignmentAllowed())
            ->setIsRetainer($template->isDefaultIsRetainer());
        foreach ($template->getTags() as $tag) {
            $project->addTag($tag);
        }
        $this->em->persist($project);
        $this->em->flush(); // project UUID needed for task identifiers

        $tasksCreated = 0;
        $bundle = $template->getTaskBundle();
        if ($bundle !== null) {
            $tasksCreated = \count($this->applyBundleToProject($bundle, $project, $startsOn, $actor));
        }
        $this->em->flush();

        return ['project' => $project, 'tasksCreated' => $tasksCreated];
    }

    /**
     * Add the bundle's TaskTemplates as Tasks on the given project. Reusable
     * standalone (apply-bundle endpoint) as well as part of instantiate.
     *
     * @return list<Task> the newly created tasks
     */
    public function applyBundleToProject(TaskBundle $bundle, Project $project, ?\DateTimeImmutable $startsOn, User $actor): array
    {
        if ($bundle->getWorkspace() !== $project->getWorkspace()) {
            throw new \RuntimeException('Cannot apply bundle from a different workspace.');
        }

        $defaultTaskStatus = $this->resolveDefaultTaskStatus($project->getWorkspace());
        if ($defaultTaskStatus === null) {
            throw new \RuntimeException('Workspace has no task status — seed at least one.');
        }

        // Existing tasks in the project so we don't collide on identifier.
        $offset = $this->nextTaskOffset($project);

        $created = [];
        /** @var array<string, Task> $templateUuidToTask map for parent linking */
        $templateUuidToTask = [];

        foreach ($bundle->getTaskTemplates() as $template) {
            \assert($template instanceof TaskTemplate);

            $dueOn = null;
            if ($startsOn !== null && $template->getDueDayOffset() !== null) {
                $dueOn = $startsOn->modify('+' . $template->getDueDayOffset() . ' days');
            }

            $offset++;
            $task = (new Task())
                ->setWorkspace($project->getWorkspace())
                ->setProject($project)
                ->setIdentifier(sprintf('%s-%d', $project->getKey(), $offset))
                ->setTitle($template->getTitle())
                ->setDescription($template->getDescription())
                ->setStatus($defaultTaskStatus)
                ->setPriority($template->getPriority())
                ->setEstimatedMinutes($template->getEstimatedMinutes())
                ->setDueOn($dueOn)
                ->setPosition($template->getPosition())
                ->setCreatedBy($actor);

            $this->em->persist($task);
            $created[] = $task;
            $templateUuidToTask[$template->getId()?->toRfc4122() ?? ''] = $task;

            // Default checklist
            $checklistPosition = 0;
            foreach ($template->getDefaultChecklist() as $name) {
                $checklistPosition += 10;
                $item = (new ChecklistItem())
                    ->setWorkspace($project->getWorkspace())
                    ->setTask($task)
                    ->setName($name)
                    ->setPosition((float) $checklistPosition);
                $this->em->persist($item);
            }
        }

        // Second pass: wire up subtask templates → subtask Tasks.
        foreach ($bundle->getTaskTemplates() as $template) {
            $parentTpl = $template->getParent();
            if ($parentTpl === null) {
                continue;
            }
            $parentTask = $templateUuidToTask[$parentTpl->getId()?->toRfc4122() ?? ''] ?? null;
            $childTask = $templateUuidToTask[$template->getId()?->toRfc4122() ?? ''] ?? null;
            if ($parentTask instanceof Task && $childTask instanceof Task) {
                $childTask->setParent($parentTask);
            }
        }

        return $created;
    }

    /**
     * Capture an existing Project's shape (name, defaults, tasks) as a new
     * ProjectTemplate. Optionally creates a fresh TaskBundle from the
     * project's current tasks.
     */
    /**
     * @return array{template: ProjectTemplate, taskTemplatesCreated: int}
     */
    public function saveAsTemplate(Project $project, string $templateName, bool $includeTasks, User $actor): array
    {
        $template = (new ProjectTemplate())
            ->setWorkspace($project->getWorkspace())
            ->setName($templateName)
            ->setDescription($project->getDescription())
            ->setColor($project->getColor())
            ->setProjectType($project->getProjectType())
            ->setDefaultBudgetMinutes($project->getBudgetMinutes())
            ->setDefaultIsBillableByDefault($project->isBillableByDefault())
            ->setDefaultDeductNonBillableHours($project->isDeductNonBillableHours())
            ->setDefaultIsMultiAssignmentAllowed($project->isMultiAssignmentAllowed())
            ->setDefaultIsRetainer($project->isRetainer());
        foreach ($project->getTags() as $tag) {
            $template->addTag($tag);
        }
        $this->em->persist($template);

        $taskTemplatesCreated = 0;
        if ($includeTasks && !$project->getTasks()->isEmpty()) {
            $bundle = (new TaskBundle())
                ->setWorkspace($project->getWorkspace())
                ->setName($templateName . ' — Tasks')
                ->setColor($project->getColor());
            $this->em->persist($bundle);

            $position = 0;
            foreach ($project->getTasks() as $task) {
                $position += 10;
                $tpl = (new TaskTemplate())
                    ->setWorkspace($project->getWorkspace())
                    ->setBundle($bundle)
                    ->setTitle($task->getTitle())
                    ->setDescription($task->getDescription())
                    ->setPriority($task->getPriority())
                    ->setEstimatedMinutes($task->getEstimatedMinutes())
                    ->setPosition($position);
                $this->em->persist($tpl);
                $taskTemplatesCreated++;
            }
            $template->setTaskBundle($bundle);
        }

        $this->em->flush();
        return ['template' => $template, 'taskTemplatesCreated' => $taskTemplatesCreated];
    }

    private function resolveProjectStatus(\App\Entity\Workspace $ws): ?ProjectStatus
    {
        // First non-archived status by position (typically "Planung" or "Aktiv").
        return $this->projectStatuses->findOneBy(
            ['workspace' => $ws, 'isArchived' => false],
            ['position' => 'ASC'],
        );
    }

    private function resolveDefaultTaskStatus(\App\Entity\Workspace $ws): ?TaskStatus
    {
        // Prefer the workspace's explicit isDefault status, fall back to first by position.
        $default = $this->taskStatuses->findOneBy(['workspace' => $ws, 'isDefault' => true]);
        if ($default !== null) {
            return $default;
        }
        return $this->taskStatuses->findOneBy(['workspace' => $ws], ['position' => 'ASC']);
    }

    private function nextTaskOffset(Project $project): int
    {
        $max = 0;
        foreach ($project->getTasks() as $task) {
            if (preg_match('/-(\d+)$/', $task->getIdentifier(), $m)) {
                $n = (int) $m[1];
                if ($n > $max) {
                    $max = $n;
                }
            }
        }
        return $max;
    }
}
