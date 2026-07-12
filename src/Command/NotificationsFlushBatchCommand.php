<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\NotificationType;
use App\Notification\NotificationChatNotifier;
use App\Notification\NotificationEmailNotifier;
use App\Notification\Preference\NotificationPreferences;
use App\Repository\NotificationRepository;
use App\Repository\UserPreferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Debounced delivery of batchable notifications (file uploads, ticket
 * assignment/updates). In-app/Mercure already fired instantly on creation; this
 * sweep collects the still-undelivered batchable notifications per recipient and,
 * once the recipient's delay window (notificationPreferences.delayMinutes) has
 * elapsed since their OLDEST pending one, sends a single bundled email + chat and
 * marks the batch delivered.
 *
 * Meant to run every few minutes from cron (frankenphp/crontab + .ddev cron).
 * Idempotent: notifications stay undelivered until actually sent (email egress
 * denied → left for the next run); already-delivered ones are never reconsidered.
 */
#[AsCommand(
    name: 'app:notifications:flush-batch',
    description: 'Deliver debounced (batched) notifications whose delay window has elapsed.',
)]
final class NotificationsFlushBatchCommand extends Command
{
    public function __construct(
        private readonly UserPreferencesRepository $preferences,
        private readonly NotificationRepository $notifications,
        private readonly NotificationEmailNotifier $emailNotifier,
        private readonly NotificationChatNotifier $chatNotifier,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $batchable = array_values(array_filter(
            NotificationType::cases(),
            static fn (NotificationType $t): bool => $t->isBatchable(),
        ));

        // Stored prefs keyed by user id (portal users typically have none → defaults).
        $prefsRowByUser = [];
        foreach ($this->preferences->findWithNotificationPreferences() as $row) {
            $uid = $row->getUser()->getId()?->toRfc4122();
            if ($uid !== null) {
                $prefsRowByUser[$uid] = $row;
            }
        }

        // Group undelivered batchable notifications by recipient (oldest first).
        $groups = [];
        foreach ($this->notifications->findUndeliveredOfTypes($batchable) as $n) {
            $rid = $n->getRecipient()->getId()?->toRfc4122();
            if ($rid === null) {
                continue;
            }
            $groups[$rid] ??= ['user' => $n->getRecipient(), 'items' => []];
            $groups[$rid]['items'][] = $n;
        }

        $emails = 0;
        $chats = 0;
        $touched = false;

        foreach ($groups as $rid => $group) {
            /** @var list<\App\Entity\Notification> $items */
            $items = $group['items'];
            $user = $group['user'];
            $prefs = NotificationPreferences::fromArray($prefsRowByUser[$rid]?->getNotificationPreferences());

            // Window: wait until the OLDEST pending item is older than the delay.
            $threshold = $now->modify('-' . $prefs->delayMinutes . ' minutes');
            if ($items[0]->getOccurredAt() > $threshold) {
                continue; // still collecting — try again next run
            }

            // For the async channels, only the types the recipient still wants.
            $enabled = array_values(array_filter(
                $items,
                static fn ($n): bool => $prefs->typeEnabled($n->getType()->value),
            ));

            $deliveredOk = true;
            if ($prefs->email && $enabled !== []) {
                if ($this->emailNotifier->sendDigest($user, $enabled)) {
                    ++$emails;
                } else {
                    $deliveredOk = false; // egress denied → retry next run
                }
            }
            if ($deliveredOk && $prefs->chat && $enabled !== []) {
                $this->chatNotifier->sendBatch($user, $enabled);
                ++$chats;
            }

            // Mark the whole batch handled (in-app was already delivered on create).
            if ($deliveredOk) {
                foreach ($items as $n) {
                    $n->markDelivered($now);
                }
                $touched = true;
            }
        }

        if ($touched) {
            $this->em->flush();
        }

        $io->success(\sprintf('Batch flush: %d email(s), %d chat(s).', $emails, $chats));

        return Command::SUCCESS;
    }
}
