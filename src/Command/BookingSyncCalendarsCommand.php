<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\StaffCalendarConnectionRepository;
use App\Service\Booking\IcsCalendarImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Polls every active staff ICS calendar feed and refreshes the booking
 * free/busy cache ({@see \App\Entity\CalendarBusyBlock}) so slots are only
 * offered when the host is actually free. Meant to run every few minutes from
 * cron. A per-connection failure is recorded on the connection (lastError) and
 * does not abort the rest of the run.
 */
#[AsCommand(
    name: 'app:booking:sync-calendars',
    description: 'Sync staff ICS calendar feeds into booking free/busy blocks.',
)]
final class BookingSyncCalendarsCommand extends Command
{
    public function __construct(
        private readonly StaffCalendarConnectionRepository $connections,
        private readonly IcsCalendarImporter $importer,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ok = 0;
        $blocks = 0;

        foreach ($this->connections->findActiveConfigured() as $connection) {
            try {
                $blocks += $this->importer->syncConnection($connection);
                ++$ok;
            } catch (\Throwable $e) {
                $connection->setLastError($e->getMessage());
                $this->em->flush();
                $io->warning(sprintf('Sync failed for %s: %s', $connection->getOwner()->getEmail(), $e->getMessage()));
            }
        }

        $io->success(sprintf('%d calendar(s) synced, %d busy block(s) imported.', $ok, $blocks));

        return Command::SUCCESS;
    }
}
