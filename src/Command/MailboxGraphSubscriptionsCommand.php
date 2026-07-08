<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\Adapter\EmailGraph\GraphSubscriptionManager;
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

/**
 * Reconciles Microsoft Graph push subscriptions for enabled `email_graph`
 * channels — create if missing, renew if expiring soon, tear down if the
 * channel was disabled. Idempotent and self-healing; run from cron a few times
 * a day (subscriptions live ~3 days, so 6-hourly leaves generous slack).
 *
 * Per-channel failures are recorded on Channel.lastSyncError and the loop
 * continues, mirroring {@see ChannelPullCommand}.
 *
 *   bin/console worktide:mailbox:graph-subscriptions:sync
 *   bin/console worktide:mailbox:graph-subscriptions:sync --channel=<uuid>
 */
#[AsCommand(
    name: 'worktide:mailbox:graph-subscriptions:sync',
    description: 'Create/renew/remove Microsoft Graph push subscriptions for email_graph channels.',
)]
final class MailboxGraphSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly ChannelRepository $channels,
        private readonly GraphSubscriptionManager $subscriptions,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Restrict to a single channel UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('channel');

        /** @var list<Channel> $all */
        $all = $this->channels->findBy(array_filter([
            'adapterCode' => 'email_graph',
            'id' => is_string($only) && $only !== '' ? $only : null,
        ], static fn ($v) => $v !== null));

        $ensured = 0;
        $removed = 0;
        foreach ($all as $channel) {
            try {
                if ($channel->isEnabled() && $channel->supports(ChannelCapability::Inbound)) {
                    $this->subscriptions->ensureSubscription($channel);
                    $channel->setLastSyncError(null);
                    ++$ensured;
                } else {
                    $this->subscriptions->unsubscribe($channel);
                    ++$removed;
                }
            } catch (\Throwable $e) {
                $channel->setLastSyncError(sprintf('graph-subscription: %s', $e->getMessage()));
                $this->em->flush();
                $io->warning(sprintf('Channel %s: %s', (string) $channel->getId()?->toRfc4122(), $e->getMessage()));
            }
        }

        $io->success(sprintf('Graph subscriptions reconciled: %d ensured, %d removed.', $ensured, $removed));

        return Command::SUCCESS;
    }
}
