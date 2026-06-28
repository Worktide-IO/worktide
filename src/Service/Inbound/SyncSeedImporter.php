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
use Doctrine\ORM\EntityManagerInterface;

/**
 * One-shot bulk seed: pulls every external record from a sync channel and
 * materialises a local {@see Task} + {@see EntitySync} mapping for each, so a
 * fresh system can be filled with live data for testing.
 *
 * This is the deliberate "fill it up" path. The normal pull
 * ({@see \App\Command\ChannelPullCommand}) only updates already-mapped entities
 * or parks unknown ones as {@see \App\Entity\DiscoveredExternalRecord} (filtered
 * by participant relevance) — which imports nothing on a brand-new system.
 *
 * Two modes:
 *  - {@see seed()} dumps every issue into one target project.
 *  - {@see seedRouted()} routes each issue to the local project its Redmine
 *    project maps to ({@see ProjectMappingService}); issues whose project is
 *    unmapped are skipped.
 *
 * Task mappings are {@see \App\Entity\Enum\SyncMode::Bidirectional} (default),
 * but outbound push stays withheld by the {@see \App\Egress\EgressGuard}
 * (`ticket_push`) until approved — so seeding never causes egress. Runs inside
 * {@see SyncReentryGuard} so creating mappings doesn't enqueue an outbound push.
 * Idempotent via UNIQUE(channel, externalId) + an entityType-scoped existence check.
 */
final class SyncSeedImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdapterRegistry $registry,
        private readonly EntitySyncRepository $mappings,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly SyncReentryGuard $guard,
        private readonly ProjectMappingService $projectMapping,
        private readonly TaskEnricher $enricher,
    ) {}

    /**
     * Single-target seed: all issues into one project.
     *
     * @return array{total: int, created: int, skipped: int}
     */
    public function seed(Channel $channel, Project $project, ?int $limit = null, bool $dryRun = false): array
    {
        if ($project->getWorkspace() !== $channel->getWorkspace()) {
            throw new \DomainException('Project and channel belong to different workspaces.');
        }

        $snapshots = $this->registry->getSync($channel->getAdapterCode())->pullEntities($channel, null);
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
                if ($this->alreadyMapped($channel, $snapshot)) {
                    ++$skipped;
                    continue;
                }
                if (!$dryRun) {
                    $this->persistTask($snapshot, $project, $status, $channel);
                }
                ++$created;
            }
            if (!$dryRun) {
                $this->em->flush();
            }
        };

        $dryRun ? $run() : $this->guard->run($run);

        return ['total' => $total, 'created' => $created, 'skipped' => $skipped];
    }

    /**
     * Routed seed: each issue goes to the local project its Redmine project maps
     * to (via {@see ProjectMappingService}). Unmapped projects are skipped.
     *
     * @return array{total: int, created: int, skipped: int, unmapped: int}
     */
    public function seedRouted(Channel $channel, ?int $limit = null, bool $dryRun = false): array
    {
        $snapshots = $this->registry->getSync($channel->getAdapterCode())->pullEntities($channel, null);

        $created = 0;
        $skipped = 0;
        $unmapped = 0;
        $total = 0;
        /** @var array<string, Project> $projectCache */
        $projectCache = [];
        /** @var array<string, TaskStatus> $statusCache */
        $statusCache = [];

        $run = function () use ($snapshots, $channel, $limit, $dryRun, &$created, &$skipped, &$unmapped, &$total, &$projectCache, &$statusCache): void {
            foreach ($snapshots as $snapshot) {
                if ($limit !== null && $total >= $limit) {
                    break;
                }
                ++$total;

                $externalProjectId = (string) ($snapshot->sourceMetadata['redmineProjectId'] ?? '');
                if ($externalProjectId === '') {
                    ++$unmapped;
                    continue;
                }
                $project = $projectCache[$externalProjectId]
                    ??= $this->projectMapping->resolveLocalProject($channel, $externalProjectId);
                if ($project === null) {
                    ++$unmapped; // this Redmine project wasn't mapped/applied
                    continue;
                }

                if ($this->alreadyMapped($channel, $snapshot)) {
                    ++$skipped;
                    continue;
                }
                if (!$dryRun) {
                    $pid = $project->getId()?->toRfc4122() ?? '';
                    $status = $statusCache[$pid] ??= $this->resolveStatus($project);
                    $this->persistTask($snapshot, $project, $status, $channel);
                }
                ++$created;
            }
            if (!$dryRun) {
                $this->em->flush();
            }
        };

        $dryRun ? $run() : $this->guard->run($run);

        return ['total' => $total, 'created' => $created, 'skipped' => $skipped, 'unmapped' => $unmapped];
    }

    /** Entity-type-scoped existence check (a project mapping may share the numeric id). */
    private function alreadyMapped(Channel $channel, EntitySnapshot $snapshot): bool
    {
        return $this->mappings->findOneBy([
            'channel' => $channel,
            'entityType' => $snapshot->entityType,
            'externalId' => $snapshot->externalId,
        ]) !== null;
    }

    private function persistTask(EntitySnapshot $snapshot, Project $project, TaskStatus $status, Channel $channel): void
    {
        $task = $this->buildTask($snapshot, $project, $status);
        $this->enricher->enrich($task, $snapshot, $channel); // status/priority/assignee/dueOn
        $this->em->persist($task);
        $this->em->persist($this->mapping($snapshot, $channel, $project, $task->getId()));
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

    private function mapping(EntitySnapshot $s, Channel $channel, Project $project, \Symfony\Component\Uid\Uuid $entityId): EntitySync
    {
        $mapping = (new EntitySync())
            ->setWorkspace($project->getWorkspace())
            ->setChannel($channel)
            ->setEntityType($s->entityType)
            ->setEntityId($entityId)
            ->setExternalId($s->externalId)
            ->setExternalUrl($s->externalUrl)
            ->setSourceMetadata($s->sourceMetadata); // retain redmine status/priority/assignee ids
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
