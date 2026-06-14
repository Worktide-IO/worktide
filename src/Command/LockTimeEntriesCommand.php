<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\TimeEntry;
use App\Entity\TimeTrackingSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks every workspace's TimeTrackingSettings.lockAfterDays and locks any
 * TimeEntry whose startsAt is older than the threshold. Idempotent — already
 * locked rows skip silently.
 *
 * Schedule daily from cron:
 *   0 1 * * * cd /var/www/worktide && bin/console app:time:auto-lock
 */
#[AsCommand(
    name: 'app:time:auto-lock',
    description: 'Lock TimeEntries older than each workspace TimeTrackingSettings.lockAfterDays.',
)]
final class LockTimeEntriesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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

        /** @var list<TimeTrackingSettings> $settings */
        $settings = $this->em->getRepository(TimeTrackingSettings::class)
            ->createQueryBuilder('s')
            ->andWhere('s.lockAfterDays IS NOT NULL')
            ->getQuery()
            ->getResult();

        $locked = 0;
        foreach ($settings as $s) {
            $cutoff = (new \DateTimeImmutable())->modify('-' . $s->getLockAfterDays() . ' days');
            $qb = $this->em->getRepository(TimeEntry::class)->createQueryBuilder('te')
                ->andWhere('te.workspace = :ws')
                ->andWhere('te.isLocked = false')
                ->andWhere('te.startsAt < :cutoff')
                ->setParameter('ws', $s->getWorkspace())
                ->setParameter('cutoff', $cutoff);

            /** @var list<TimeEntry> $entries */
            $entries = $qb->getQuery()->getResult();
            foreach ($entries as $te) {
                if (!$dryRun) {
                    $te->setIsLocked(true);
                }
                $locked++;
            }
            if (\count($entries) > 0) {
                $io->writeln(sprintf(
                    '  %s: locking %d entries older than %s',
                    $s->getWorkspace()->getSlug(),
                    \count($entries),
                    $cutoff->format('Y-m-d'),
                ));
            }
        }

        if (!$dryRun && $locked > 0) {
            $this->em->flush();
        }
        $io->success(sprintf('%d entries %s.', $locked, $dryRun ? 'would be locked (dry-run)' : 'locked'));
        return Command::SUCCESS;
    }
}
