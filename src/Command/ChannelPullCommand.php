<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\AdapterRegistry;
use App\Channels\PullNotSupportedException;
use App\Channels\UnknownAdapterException;
use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Restrict to a single channel UUID')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Restrict to channels in this workspace');
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

        $totalEvents = 0;
        foreach ($channels as $channel) {
            $io->section(sprintf('%s [%s]', $channel->getName(), $channel->getAdapterCode()));
            try {
                $adapter = $this->registry->getInbound($channel->getAdapterCode());
            } catch (UnknownAdapterException $e) {
                $io->error($e->getMessage());
                continue;
            }
            try {
                $result = $adapter->pull($channel);
                $count = \count($result->events);
                $totalEvents += $count;
                $io->writeln(sprintf(' → %d new event(s); cursor=%s', $count, $result->cursor ?? '(unchanged)'));

                // Persist the cursor + last-synced markers on the channel
                // so the next run resumes from where we stopped.
                $cfg = $channel->getInboundConfig();
                if ($result->cursor !== null) {
                    $cfg['cursor'] = $result->cursor;
                    $channel->setInboundConfig($cfg);
                }
                $channel->setLastSyncedAt(new \DateTimeImmutable());
                $channel->setLastSyncError(null);
            } catch (PullNotSupportedException) {
                // Push-only adapter — nothing to do for a pull run.
                $io->writeln(' (push-only adapter; skipping)');
            } catch (\Throwable $e) {
                $channel->setLastSyncError(sprintf('%s: %s', $e::class, $e->getMessage()));
                $io->error(sprintf('Pull failed: %s', $e->getMessage()));
            }
            $this->em->flush();
        }

        $io->success(sprintf('Pulled %d new event(s) across %d channel(s).', $totalEvents, \count($channels)));
        return Command::SUCCESS;
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
