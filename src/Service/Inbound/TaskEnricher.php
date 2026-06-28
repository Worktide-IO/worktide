<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Channels\EntitySnapshot;
use App\Channels\ExternalParticipant;
use App\Entity\Channel;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Enum\TaskPriority;
use App\Entity\Task;
use App\Entity\TaskAssignee;
use App\Entity\TaskStatus;
use App\Entity\Workspace;
use App\Repository\TaskStatusRepository;

/**
 * Maps an external ticket's status / priority / assignee / due date from a
 * {@see EntitySnapshot} onto a local {@see Task}, beyond the plain
 * title+description the importer sets. Worktide-side resolution lives here:
 *
 *  - status   → workspace TaskStatus matched by name (case-insensitive); if no
 *               match, the importer's default status is kept.
 *  - priority → mapped from the external name (Niedrig/Mittel/Hoch → enum).
 *  - assignee → the assignee participant resolved to a local User via
 *               {@see InboundImportFilter} (explicit ExternalIdentity, then email).
 *  - dueOn    → parsed from the external due date.
 *
 * Used by the seed importer; the same resolution can later back the ongoing-pull
 * EntityApplier so live updates enrich too.
 */
final class TaskEnricher
{
    public function __construct(
        private readonly TaskStatusRepository $taskStatuses,
        private readonly InboundImportFilter $importFilter,
    ) {}

    public function enrich(Task $task, EntitySnapshot $snapshot, Channel $channel): void
    {
        $meta = $snapshot->sourceMetadata;

        $statusName = $meta['redmineStatusName'] ?? null;
        if (\is_string($statusName) && $statusName !== '') {
            $status = $this->matchStatus($task->getWorkspace(), $statusName);
            if ($status !== null) {
                $task->setStatus($status);
            }
        }

        $task->setPriority($this->mapPriority(\is_string($meta['redminePriorityName'] ?? null) ? $meta['redminePriorityName'] : null));

        $due = $meta['redmineDueDate'] ?? null;
        if (\is_string($due) && $due !== '') {
            try {
                $task->setDueOn(new \DateTimeImmutable($due));
            } catch (\Exception) {
                // ignore unparseable date
            }
        }

        $this->applyAssignee($task, $snapshot, $channel);
    }

    private function applyAssignee(Task $task, EntitySnapshot $snapshot, Channel $channel): void
    {
        foreach ($snapshot->participants as $participant) {
            if ($participant->role !== ExternalParticipant::ROLE_ASSIGNEE) {
                continue;
            }
            $user = $this->importFilter->resolveUser($channel, $participant);
            if ($user !== null && $user->getId() !== null) {
                $task->addAssignedPrincipal(
                    (new TaskAssignee())
                        ->setPrincipalType(AssigneePrincipalType::User)
                        ->setPrincipalId($user->getId()),
                );
            }

            return; // only the assignee participant
        }
    }

    private function matchStatus(Workspace $workspace, string $name): ?TaskStatus
    {
        $needle = mb_strtolower(trim($name));
        foreach ($this->taskStatuses->findBy(['workspace' => $workspace]) as $status) {
            if (mb_strtolower($status->getName()) === $needle) {
                return $status;
            }
        }

        return null;
    }

    private function mapPriority(?string $name): TaskPriority
    {
        return match (mb_strtolower(trim((string) $name))) {
            'niedrig', 'low', 'tief' => TaskPriority::Low,
            'hoch', 'high' => TaskPriority::High,
            'dringend', 'urgent', 'sofort', 'kritisch' => TaskPriority::Urgent,
            default => TaskPriority::Normal, // Mittel / Normal / unknown
        };
    }
}
