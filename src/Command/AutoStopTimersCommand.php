<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ActiveTimer;
use App\Entity\Enum\NotificationType;
use App\Entity\Notification;
use App\Entity\TimeTrackingSettings;
use App\Service\Timer\TimerCloser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks every workspace's TimeTrackingSettings.autoStopMinutes and stops any
 * ActiveTimer that has been running longer than the limit. The resulting
 * TimeEntry is capped to exactly the limit (endsAt = startedAt + limit) so a
 * timer forgotten overnight books the limit, not 14 hours. The owner gets a
 * notification.
 *
 * Idempotent — a timer is stopped once (it's removed) and re-runs skip
 * workspaces without a limit.
 *
 * Schedule every minute from cron (see frankenphp/crontab):
 *   * * * * * cd /app && bin/console app:time:auto-stop-timers
 */
#[AsCommand(
    name: 'app:time:auto-stop-timers',
    description: 'Auto-stop running timers older than each workspace TimeTrackingSettings.autoStopMinutes.',
)]
final class AutoStopTimersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimerCloser $timerCloser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable();

        /** @var list<TimeTrackingSettings> $settings */
        $settings = $this->em->getRepository(TimeTrackingSettings::class)
            ->createQueryBuilder('s')
            ->andWhere('s.autoStopMinutes IS NOT NULL')
            ->getQuery()
            ->getResult();

        $stopped = 0;
        foreach ($settings as $s) {
            $limit = (int) $s->getAutoStopMinutes();
            if ($limit <= 0) {
                continue;
            }
            $cutoff = $now->modify("-{$limit} minutes");

            /** @var list<ActiveTimer> $timers */
            $timers = $this->em->getRepository(ActiveTimer::class)->createQueryBuilder('t')
                ->andWhere('t.workspace = :ws')
                ->andWhere('t.startedAt <= :cutoff')
                ->setParameter('ws', $s->getWorkspace())
                ->setParameter('cutoff', $cutoff)
                ->getQuery()
                ->getResult();

            foreach ($timers as $timer) {
                $cappedEnd = $timer->getStartedAt()->modify("+{$limit} minutes");
                if (!$dryRun) {
                    $this->timerCloser->close($timer, $cappedEnd);
                    $this->em->persist($this->buildNotification($timer, $limit));
                }
                $stopped++;
            }

            if (\count($timers) > 0) {
                $io->writeln(sprintf(
                    '  %s: stopping %d timer(s) running longer than %d min',
                    $s->getWorkspace()->getSlug(),
                    \count($timers),
                    $limit,
                ));
            }
        }

        if (!$dryRun && $stopped > 0) {
            $this->em->flush();
        }
        $io->success(sprintf('%d timer(s) %s.', $stopped, $dryRun ? 'would be stopped (dry-run)' : 'stopped'));
        return Command::SUCCESS;
    }

    private function buildNotification(ActiveTimer $timer, int $limit): Notification
    {
        $context = $timer->getProject()?->getName() ?? $timer->getDescription();
        $body = sprintf(
            'Dein Timer%s lief länger als %d %s und wurde automatisch gestoppt. Bitte prüfe den Zeiteintrag.',
            $context !== null && $context !== '' ? ' („' . $context . '“)' : '',
            $limit,
            $limit === 1 ? 'Minute' : 'Minuten',
        );

        return new Notification(
            $timer->getUser(),
            NotificationType::System,
            'Timer automatisch gestoppt',
            '/time-entries',
            $body,
            $timer->getWorkspace(),
        );
    }
}
