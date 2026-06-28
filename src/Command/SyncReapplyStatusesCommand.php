<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Repository\EntitySyncRepository;
use App\Repository\TaskStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Re-applies the channel's current status map (inboundConfig.statusMap) to the
 * tasks already imported from that channel — without re-importing. Use after
 * editing the status map (e.g. worktide:sync:map-statuses adding synonyms) to
 * pull existing tasks onto the new mapping.
 *
 *   bin/console worktide:sync:reapply-statuses --channel=<uuid> [--dry-run]
 *
 * Reads each task mapping's stored redmineStatusId and sets the mapped status.
 * Runs inside SyncReentryGuard so it doesn't enqueue an outbound push.
 */
#[AsCommand(
    name: 'worktide:sync:reapply-statuses',
    description: 'Re-apply the channel status map to already-imported tasks.',
)]
final class SyncReapplyStatusesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntitySyncRepository $syncs,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly SyncReentryGuard $guard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Sync channel UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report changes without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $channelId = (string) $input->getOption('channel');
        try {
            $channel = $channelId !== '' ? $this->em->find(Channel::class, Uuid::fromString($channelId)) : null;
        } catch (\InvalidArgumentException) {
            $io->error('--channel is not a valid UUID.');
            return Command::INVALID;
        }
        if (!$channel instanceof Channel) {
            $io->error('Channel not found.');
            return Command::INVALID;
        }

        $statusMap = $channel->getInboundConfig()['statusMap'] ?? null;
        if (!\is_array($statusMap) || $statusMap === []) {
            $io->error('Channel has no statusMap; run worktide:sync:map-statuses first.');
            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var array<string, TaskStatus> $statusCache */
        $statusCache = [];
        $changed = 0;
        $unchanged = 0;
        $skipped = 0;

        $apply = function () use ($channel, $statusMap, $dryRun, &$statusCache, &$changed, &$unchanged, &$skipped): void {
            $mappings = $this->syncs->findBy(['channel' => $channel, 'entityType' => 'task']);
            foreach ($mappings as $mapping) {
                $rid = $mapping->getSourceMetadata()['redmineStatusId'] ?? null;
                $targetUuid = $rid !== null ? ($statusMap[(string) $rid] ?? null) : null;
                if (!\is_string($targetUuid)) {
                    ++$skipped;
                    continue;
                }
                $status = $statusCache[$targetUuid] ??= $this->loadStatus($targetUuid);
                $task = $this->em->find(Task::class, $mapping->getEntityId());
                if (!$task instanceof Task || $status === null) {
                    ++$skipped;
                    continue;
                }
                if ($task->getStatus() === $status) {
                    ++$unchanged;
                    continue;
                }
                if (!$dryRun) {
                    $task->setStatus($status);
                }
                ++$changed;
            }
            if (!$dryRun) {
                $this->em->flush();
            }
        };

        $dryRun ? $apply() : $this->guard->run($apply);

        $io->success(sprintf(
            '%s — changed %d, unchanged %d, skipped %d.',
            $dryRun ? 'Dry run' : 'Statuses re-applied',
            $changed,
            $unchanged,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function loadStatus(string $uuid): ?TaskStatus
    {
        try {
            return $this->taskStatuses->find(Uuid::fromString($uuid));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
