<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\AdapterRegistry;
use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\Task;
use App\Repository\EntitySyncRepository;
use App\Service\Inbound\DiscoveredRecordImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Backfills schedule/effort (start date, due date, estimated hours) from the
 * source onto already-imported tasks — for tickets seeded before the adapter
 * learned to carry those fields. Re-pulls fresh snapshots and applies the same
 * {@see DiscoveredRecordImporter::applyScheduleFields()} used on import.
 *
 *   bin/console worktide:sync:reapply-schedule --channel=<uuid> [--dry-run]
 *
 * Non-destructive: only sets fields the source provides (never nulls a locally
 * set value). On a read-only inbound channel the source is authoritative, so a
 * source value does overwrite a local edit. Runs inside SyncReentryGuard so it
 * doesn't enqueue an outbound push.
 */
#[AsCommand(
    name: 'worktide:sync:reapply-schedule',
    description: 'Backfill start/due dates and estimate from the source onto already-imported tasks.',
)]
final class SyncReapplyScheduleCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdapterRegistry $registry,
        private readonly EntitySyncRepository $syncs,
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

        $dryRun = (bool) $input->getOption('dry-run');
        $snapshots = $this->registry->getSync($channel->getAdapterCode())->pullEntities($channel, null);

        $changed = 0;
        $unchanged = 0;
        $skipped = 0;

        $apply = function () use ($channel, $snapshots, $dryRun, &$changed, &$unchanged, &$skipped): void {
            foreach ($snapshots as $snapshot) {
                $mapping = $this->syncs->findByChannelExternal($channel, $snapshot->externalId);
                if ($mapping === null || $mapping->getEntityType() !== 'task') {
                    ++$skipped;
                    continue;
                }
                $task = $this->em->find(Task::class, $mapping->getEntityId());
                if (!$task instanceof Task) {
                    ++$skipped;
                    continue;
                }

                $before = $this->scheduleSignature($task);
                DiscoveredRecordImporter::applyScheduleFields($task, $snapshot->fields);
                if ($this->scheduleSignature($task) === $before) {
                    ++$unchanged;
                } else {
                    ++$changed;
                }
            }
            if (!$dryRun) {
                $this->em->flush();
            }
        };

        $dryRun ? $apply() : $this->guard->run($apply);

        $io->success(sprintf(
            '%s — changed %d, unchanged %d, skipped %d.',
            $dryRun ? 'Dry run' : 'Schedule re-applied',
            $changed,
            $unchanged,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /** @return array{?string, ?string, ?int} start / due / estimatedMinutes */
    private function scheduleSignature(Task $task): array
    {
        return [
            $task->getStartOn()?->format('c'),
            $task->getDueOn()?->format('c'),
            $task->getEstimatedMinutes(),
        ];
    }
}
