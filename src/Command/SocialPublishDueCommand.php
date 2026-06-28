<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\SocialPostStatus;
use App\Message\PublishSocialPostMessage;
use App\Repository\SocialPostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Flips due scheduled posts to Publishing and dispatches the fan-out, and
 * re-dispatches posts already Publishing that still have queued targets (the
 * retry safety net). The actual network calls happen in
 * {@see \App\MessageHandler\PublishSocialPostHandler} so a slow network can't
 * hold up this tick.
 *
 * Run from cron every minute:
 *   * * * * * cd /var/www/worktide && bin/console app:social:publish-due
 *
 * Idempotent: a Scheduled post is advanced to Publishing exactly once here; the
 * handler only touches Queued targets, so re-runs don't double-post.
 */
#[AsCommand(
    name: 'app:social:publish-due',
    description: 'Dispatch scheduled/queued social posts whose publish time has arrived.',
)]
final class SocialPublishDueCommand extends Command
{
    public function __construct(
        private readonly SocialPostRepository $posts,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Override "now" (ISO-8601) for testing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max posts per tick.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $nowOpt = $input->getOption('now');
        $now = $nowOpt !== null ? new \DateTimeImmutable((string) $nowOpt) : new \DateTimeImmutable();
        $limit = max(1, (int) $input->getOption('limit'));

        $posts = $this->posts->findPublishable($now, $limit);
        if ($posts === []) {
            $io->writeln('<comment>No social posts due.</comment>');
            return Command::SUCCESS;
        }

        // Advance Scheduled → Publishing first, then commit, then dispatch —
        // same ordering as ChannelPullCommand so the worker always finds the
        // row in its committed state.
        $ids = [];
        foreach ($posts as $post) {
            if ($post->getStatus() === SocialPostStatus::Scheduled) {
                $post->setStatus(SocialPostStatus::Publishing);
            }
            $ids[] = $post->getId();
        }
        $this->em->flush();

        foreach ($ids as $id) {
            if ($id !== null) {
                $this->bus->dispatch(new PublishSocialPostMessage($id));
            }
        }

        $io->success(sprintf('Dispatched %d social post(s) for publishing.', \count($ids)));
        return Command::SUCCESS;
    }
}
