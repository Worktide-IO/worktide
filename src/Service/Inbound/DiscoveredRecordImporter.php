<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Channels\SyncReentryGuard;
use App\Entity\DiscoveredExternalRecord;
use App\Entity\Enum\ChannelCapability;
use App\Entity\Enum\DiscoveredRecordState;
use App\Entity\Enum\SyncMode;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\EntitySync;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Repository\TaskStatusRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Acts on an operator's decision for a {@see DiscoveredExternalRecord}: import
 * (new Task), link (existing Task), or dismiss — the resolution side of the
 * discovered-import path (C.7.6).
 *
 * Import and link both create the {@see EntitySync} mapping that was missing
 * (which is why the record was discovered in the first place); from then on the
 * normal bidirectional sync keeps the pair in step. Writes run inside the
 * {@see SyncReentryGuard} so creating the mapping doesn't bounce an outbound
 * push straight back to the source.
 *
 * Each action requires the record to still be {@see DiscoveredRecordState::Pending}
 * — a second call throws {@see \DomainException} (the controller maps it to 409),
 * so a double-click can't create two tasks.
 */
final class DiscoveredRecordImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly SyncReentryGuard $guard,
    ) {}

    public function import(DiscoveredExternalRecord $record, Project $project): Task
    {
        $this->assertPending($record);
        $this->assertSameWorkspace($record, $project);

        return $this->guard->run(function () use ($record, $project): Task {
            $task = $this->buildTask($record, $project);
            $this->em->persist($task);

            $this->em->persist($this->mapping($record, $task->getId()));

            $record
                ->setState(DiscoveredRecordState::Imported)
                ->setImportedEntityId($task->getId());

            $this->em->flush();

            return $task;
        });
    }

    public function link(DiscoveredExternalRecord $record, Task $task): EntitySync
    {
        $this->assertPending($record);
        if ($task->getWorkspace() !== $record->getWorkspace()) {
            throw new \DomainException('Task belongs to a different workspace.');
        }

        return $this->guard->run(function () use ($record, $task): EntitySync {
            $mapping = $this->mapping($record, $task->getId());
            $this->em->persist($mapping);

            $record
                ->setState(DiscoveredRecordState::Linked)
                ->setImportedEntityId($task->getId());

            $this->em->flush();

            return $mapping;
        });
    }

    public function dismiss(DiscoveredExternalRecord $record): void
    {
        $this->assertPending($record);
        $record->setState(DiscoveredRecordState::Dismissed);
        $this->em->flush();
    }

    private function buildTask(DiscoveredExternalRecord $record, Project $project): Task
    {
        $fields = $record->getFields();
        $title = (string) ($fields['title'] ?? '');
        $description = $fields['description'] ?? null;

        $task = (new Task())
            ->setWorkspace($project->getWorkspace())
            ->setProject($project)
            ->setTitle($title !== '' ? $title : $record->getTitle())
            ->setDescription($description !== null ? (string) $description : null)
            ->setStatus($this->resolveStatus($project))
            ->setPriority(TaskPriority::Normal)
            ->setCreatedVia(TaskCreatedVia::Api)
            ->setIdentifier($project->getKey() . '-' . dechex(random_int(0x1000, 0xFFFF)));

        self::applyScheduleFields($task, $fields);

        return $task;
    }

    /**
     * Apply optional schedule/effort fields carried by the snapshot (e.g.
     * Redmine start_date / due_date / estimated_hours). Absent or unparsable
     * values leave the task untouched.
     *
     * @param array<string, mixed> $fields
     */
    public static function applyScheduleFields(Task $task, array $fields): void
    {
        if (($start = self::parseDate($fields['startOn'] ?? null)) !== null) {
            $task->setStartOn($start);
        }
        if (($due = self::parseDate($fields['dueOn'] ?? null)) !== null) {
            $task->setDueOn($due);
        }
        if (isset($fields['estimatedMinutes']) && is_numeric($fields['estimatedMinutes'])) {
            $task->setEstimatedMinutes((int) $fields['estimatedMinutes']);
        }
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function mapping(DiscoveredExternalRecord $record, \Symfony\Component\Uid\Uuid $entityId): EntitySync
    {
        return (new EntitySync())
            ->setWorkspace($record->getWorkspace())
            ->setChannel($record->getChannel())
            ->setEntityType($record->getEntityType())
            ->setEntityId($entityId)
            ->setExternalId($record->getExternalId())
            ->setExternalUrl($record->getExternalUrl())
            // Read-only source (channel without the `outbound` capability) →
            // inbound-only mapping so local edits are never pushed back.
            ->setSyncMode(
                $record->getChannel()->supports(ChannelCapability::Outbound)
                    ? SyncMode::Bidirectional
                    : SyncMode::Inbound,
            );
    }

    private function resolveStatus(Project $project): TaskStatus
    {
        $workspace = $project->getWorkspace();

        $default = $this->taskStatuses->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
        if ($default !== null) {
            return $default;
        }

        $statuses = $this->taskStatuses->findBy(['workspace' => $workspace], ['position' => 'ASC'], 1);
        $first = $statuses[0] ?? null;
        if ($first === null) {
            throw new \RuntimeException('Workspace has no task statuses; cannot import a discovered record.');
        }

        return $first;
    }

    private function assertPending(DiscoveredExternalRecord $record): void
    {
        if ($record->getState() !== DiscoveredRecordState::Pending) {
            throw new \DomainException(sprintf(
                'Discovered record is already %s.',
                $record->getState()->value,
            ));
        }
    }

    private function assertSameWorkspace(DiscoveredExternalRecord $record, Project $project): void
    {
        if ($project->getWorkspace() !== $record->getWorkspace()) {
            throw new \DomainException('Project belongs to a different workspace than the discovered record.');
        }
    }
}
