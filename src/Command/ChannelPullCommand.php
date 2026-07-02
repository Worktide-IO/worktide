<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\AdapterRegistry;
use App\Channels\PullNotSupportedException;
use App\Channels\UnknownAdapterException;
use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Message\ProcessInboundEventMessage;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Pulls one (--channel=<uuid>) or every enabled inbound channel.
 *
 * Designed to run from cron (every 60s typically). Failures on a
 * single channel are caught and recorded as `Channel.lastSyncError`
 * — the loop continues so a broken IMAP server doesn't starve the
 * rest of the workspace's mailboxes.
 *
 *   bin/console worktide:channel:pull
 *   bin/console worktide:channel:pull --channel=<uuid>
 *   bin/console worktide:channel:pull --workspace=<uuid>
 */
#[AsCommand(
    name: 'worktide:channel:pull',
    description: 'Pull inbound events from one or every enabled channel.',
)]
final class ChannelPullCommand extends Command
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly ChannelRepository $channels,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Restrict to a single channel UUID')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Restrict to channels in this workspace')
            ->addOption('backfill', null, InputOption::VALUE_NONE, 'Loop each channel until exhausted (bounded/throttled backfill) instead of one batch')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Backfill: stop after N batches per channel (0 = until exhausted)', '0')
            ->addOption('throttle-ms', null, InputOption::VALUE_REQUIRED, 'Backfill: pause between batches in milliseconds', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('channel');
        $wsId = $input->getOption('workspace');

        $channels = $this->collectChannels($only, $wsId);
        if ($channels === []) {
            $io->writeln('<comment>No matching enabled inbound channels.</comment>');
            return Command::SUCCESS;
        }

        $backfill = (bool) $input->getOption('backfill');
        $maxBatches = max(0, (int) $input->getOption('max-batches'));
        $throttleMs = max(0, (int) $input->getOption('throttle-ms'));

        $totalEvents = 0;
        foreach ($channels as $channel) {
            $io->section(sprintf('%s [%s]', $channel->getName(), $channel->getAdapterCode()));

            if (!$backfill) {
                $totalEvents += max(0, $this->pullBatch($channel, $io, live: true));
                continue;
            }

            // Backfill: keep pulling batches until the channel is exhausted (a
            // batch returns 0 new events) or --max-batches is reached, pausing
            // --throttle-ms between batches. Each batch persists the cursor, so
            // a stop/restart resumes where it left off.
            $batches = 0;
            while (true) {
                $n = $this->pullBatch($channel, $io, live: false);
                if ($n <= 0) {
                    break; // exhausted (0) or error (-1, already logged)
                }
                $totalEvents += $n;
                if ($maxBatches > 0 && ++$batches >= $maxBatches) {
                    $io->writeln(sprintf(' (--max-batches=%d reached; re-run to continue)', $maxBatches));
                    break;
                }
                if ($throttleMs > 0) {
                    usleep($throttleMs * 1000);
                }
            }
        }

        $io->success(sprintf('Pulled %d new event(s) across %d channel(s).', $totalEvents, \count($channels)));
        return Command::SUCCESS;
    }

    /**
     * Pull a single batch for one channel: persists the cursor + sync markers,
     * dispatches processing for each new event. Errors are caught and recorded
     * on the channel (the caller decides whether to continue).
     *
     * @return int number of new events; 0 when exhausted/push-only, -1 on error
     */
    private function pullBatch(Channel $channel, SymfonyStyle $io, bool $live): int
    {
        try {
            $adapter = $this->registry->getInbound($channel->getAdapterCode());
        } catch (UnknownAdapterException $e) {
            $io->error($e->getMessage());
            return -1;
        }

        $pulledEvents = [];
        try {
            $result = $adapter->pull($channel);
            $pulledEvents = $result->events;
            $io->writeln(sprintf(' → %d new event(s); cursor=%s', \count($pulledEvents), $result->cursor ?? '(unchanged)'));

            $cfg = $channel->getInboundConfig();
            if ($result->cursor !== null) {
                $cfg['cursor'] = $result->cursor;
                $channel->setInboundConfig($cfg);
            }
            $channel->setLastSyncedAt(new \DateTimeImmutable());
            $channel->setLastSyncError(null);
        } catch (PullNotSupportedException) {
            $io->writeln(' (push-only adapter; skipping)');
            return 0;
        } catch (\Throwable $e) {
            $channel->setLastSyncError(sprintf('%s: %s', $e::class, $e->getMessage()));
            $io->error(sprintf('Pull failed: %s', $e->getMessage()));
            $this->em->flush();
            return -1;
        }

        $this->em->flush();

        // Dispatch after flush (same as the webhook path) so the worker finds
        // each row committed.
        foreach ($pulledEvents as $event) {
            $this->bus->dispatch(new ProcessInboundEventMessage($event->getId(), live: $live));
        }

        return \count($pulledEvents);
    }

    /**
     * @return list<Channel>
     */
    private function collectChannels(?string $channelId, ?string $workspaceId): array
    {
        if ($channelId !== null && $channelId !== '') {
            try {
                $channel = $this->em->find(Channel::class, Uuid::fromString($channelId));
            } catch (\InvalidArgumentException) {
                return [];
            }
            if (!$channel instanceof Channel || !$channel->isEnabled() || !$channel->supports(ChannelCapability::Inbound)) {
                return [];
            }
            return [$channel];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Channel::class, 'c')
            ->where('c.isEnabled = 1')
            ->andWhere('c.deletedAt IS NULL');

        if ($workspaceId !== null && $workspaceId !== '') {
            try {
                $wsUuid = Uuid::fromString($workspaceId);
            } catch (\InvalidArgumentException) {
                return [];
            }
            $qb->andWhere('c.workspace = :ws')->setParameter('ws', $wsUuid);
        }

        /** @var list<Channel> $candidates */
        $candidates = $qb->getQuery()->getResult();
        // DQL has no portable JSON_CONTAINS; filter capabilities in PHP.
        return array_values(array_filter(
            $candidates,
            fn (Channel $c) => $c->supports(ChannelCapability::Inbound),
        ));
    }
}
