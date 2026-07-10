<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\NotificationEmailNotifier;
use App\Notification\Preference\NotificationPreferences;
use App\Repository\NotificationRepository;
use App\Repository\UserPreferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the daily/weekly notification digest.
 *
 * For every user who opted into a matching email cadence, bundles their unread
 * notifications since the last digest (respecting per-type opt-outs) into one
 * email. Meant to run from cron: `--frequency=daily` once a day,
 * `--frequency=weekly` once a week (see frankenphp/crontab + .ddev cron).
 *
 * Idempotent-ish: `lastNotificationDigestAt` is advanced only when a run was
 * actually delivered (or there was nothing to send). If egress is off the
 * marker is left untouched, so the notifications roll into the next run once
 * email is allowed again.
 */
#[AsCommand(
    name: 'app:notifications:send-digest',
    description: 'Send the daily/weekly notification digest to users who opted in.',
)]
final class NotificationsSendDigestCommand extends Command
{
    private const WINDOW = [
        NotificationPreferences::FREQ_DAILY => '-1 day',
        NotificationPreferences::FREQ_WEEKLY => '-7 days',
    ];

    public function __construct(
        private readonly UserPreferencesRepository $preferences,
        private readonly NotificationRepository $notifications,
        private readonly NotificationEmailNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'frequency',
            null,
            InputOption::VALUE_REQUIRED,
            'Which cadence to send: daily or weekly.',
            NotificationPreferences::FREQ_DAILY,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $frequency = (string) $input->getOption('frequency');
        if (!isset(self::WINDOW[$frequency])) {
            $io->error('frequency must be "daily" or "weekly".');

            return Command::INVALID;
        }

        $now = new \DateTimeImmutable();
        // Floor for users who never received a digest before — avoids emailing a
        // brand-new opt-in their entire backlog.
        $fallbackSince = $now->modify(self::WINDOW[$frequency]);
        $sent = 0;
        $touched = false;

        foreach ($this->preferences->findWithNotificationPreferences() as $row) {
            $prefs = NotificationPreferences::fromArray($row->getNotificationPreferences());
            if (!$prefs->email || $prefs->frequency !== $frequency) {
                continue;
            }

            $user = $row->getUser();
            $since = $row->getLastNotificationDigestAt() ?? $fallbackSince;
            $items = array_values(array_filter(
                $this->notifications->findUnreadForRecipientSince($user, $since, 50),
                fn ($n) => $prefs->typeEnabled($n->getType()->value),
            ));

            if ($items === []) {
                $row->setLastNotificationDigestAt($now); // nothing pending — keep window tight
                $touched = true;
                continue;
            }

            if ($this->notifier->sendDigest($user, $items)) {
                ++$sent;
                $row->setLastNotificationDigestAt($now);
                $touched = true;
            }
            // else: egress denied — leave the marker so these roll into the next run.
        }

        if ($touched) {
            $this->em->flush();
        }

        $io->success(\sprintf('%s digest: %d email(s) sent.', $frequency, $sent));

        return Command::SUCCESS;
    }
}
