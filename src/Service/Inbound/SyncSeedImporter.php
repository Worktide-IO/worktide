<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Channels\AdapterRegistry;
use App\Channels\EntitySnapshot;
use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\EntitySync;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Repository\EntitySyncRepository;
use App\Repository\TaskStatusRepository;
use Symfony\Component\Uid\Uuid;

/**
 * One-shot bulk seed: pulls every external record from a sync channel and
 * materialises a local {@see Task} + {@see EntitySync} mapping for each, so a
 * fresh system can be filled with live data for testing.
 *
 * This is the deliberate "fill it up" path. The normal pull
 * ({@see \App\Command\ChannelPullCommand}) only updates already-mapped entities
 * or parks unknown ones as {@see \App\Entity\DiscoveredExternalRecord} (filtered
 * by participant relevance) — which imports nothing on a brand-new system. The
 * seed importer bypasses that and maps everything.
 *
 * Mappings are created {@see \App\Entity\Enum\SyncMode::Bidirectional} (the
 * default), but outbound push stays withheld by the {@see \App\Egress\EgressGuard}
 * (`ticket_push`) until the operator approves it — so seeding never causes egress.
 * Runs inside {@see SyncReentryGuard} so creating mappings doesn't enqueue an
 * outbound push back to the source. Idempotent via UNIQUE(channel, externalId).
 */
final class SyncSeedImporter
{
    public function __construct(
        private readonly \Doctrine\ORM\EntityManagerInterface $em,
        private readonly AdapterRegistry $registry,
        private readonly EntitySyncRepository $mappings,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly SyncReentryGuard $guard,
    ) {}

    /**
     * @return array{total: int, created: int, skipped: int}
     */
    public function seed(Channel $channel, Project $project, ?int $limit = null, bool $dryRun = false): array
    {
        if ($project->getWorkspace() !== $channel->getWorkspace()) {
            throw new \DomainException('Project and channel belong to different workspaces.');
        }

        $adapter = $this->registry->getSync($channel->getAdapterCode());
        $snapshots = $adapter->pullEntities($channel, null);
        $status = $this->resolveStatus($project);

        $created = 0;
        $skipped = 0;
        $total = 0;

        $run = function () use ($snapshots, $channel, $project, $status, $limit, $dryRun, &$created, &$skipped, &$total): void {
            foreach ($snapshots as $snapshot) {
                if ($limit !== null && $total >= $limit) {
                    break;
                }
                ++$total;

                $existing = $this->mappings->findOneBy([
                    'channel' => $channel,
                    'externalId' => $snapshot->externalId,
                ]);
                if ($existing !== null) {
                    ++$skipped;
                    continue;
                }

                if ($dryRun) {
                    ++$created; // would-create
                    continue;
                }

                $task = $this->buildTask($snapshot, $project, $status);
                $this->em->persist($task);
                $this->em->persist($this->mapping($snapshot, $channel, $project, $task->getId()));
                ++$created;
            }

            if (!$dryRun) {
                $this->em->flush();
            }
        };

        // Guard prevents the freshly created mappings from bouncing an outbound push.
        $dryRun ? $run() : $this->guard->run($run);

        return ['total' => $total, 'created' => $created, 'skipped' => $skipped];
    }

    private function buildTask(EntitySnapshot $s, Project $project, TaskStatus $status): Task
    {
        $title = (string) ($s->fields['title'] ?? '');
        $description = $s->fields['description'] ?? null;

        return (new Task())
            ->setWorkspace($project->getWorkspace())
            ->setProject($project)
            ->setTitle($title !== '' ? $title : ('Imported ' . $s->externalId))
            ->setDescription($description !== null ? (string) $description : null)
            ->setStatus($status)
            ->setPriority(TaskPriority::Normal)
            ->setCreatedVia(TaskCreatedVia::Import)
            // 6 hex digits (~16M space) keeps bulk-import identifier collisions negligible.
            ->setIdentifier($project->getKey() . '-' . dechex(random_int(0x100000, 0xFFFFFF)));
    }

    private function mapping(EntitySnapshot $s, Channel $channel, Project $project, Uuid $entityId): EntitySync
    {
        $mapping = (new EntitySync())
            ->setWorkspace($project->getWorkspace())
            ->setChannel($channel)
            ->setEntityType($s->entityType)
            ->setEntityId($entityId)
            ->setExternalId($s->externalId)
            ->setExternalUrl($s->externalUrl);
        if ($s->externalUpdatedAt !== null) {
            $mapping->setExternalUpdatedAt($s->externalUpdatedAt);
        }
        if ($s->etag !== null) {
            $mapping->setEtag($s->etag);
        }

        return $mapping;
    }

    private function resolveStatus(Project $project): TaskStatus
    {
        $workspace = $project->getWorkspace();

        $default = $this->taskStatuses->findOneBy(['workspace' => $workspace, 'isDefault' => true]);
        if ($default !== null) {
            return $default;
        }

        $first = $this->taskStatuses->findBy(['workspace' => $workspace], ['position' => 'ASC'], 1)[0] ?? null;
        if ($first === null) {
            throw new \RuntimeException('Workspace has no task statuses; cannot seed-import.');
        }

        return $first;
    }
}
