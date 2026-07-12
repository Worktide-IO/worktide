<?php

declare(strict_types=1);

namespace App\Notification;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\Preference\NotificationPreferences;
use App\Repository\UserPreferencesRepository;
use App\Service\I18n\RecipientLocaleResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Bridges freshly-created {@see Notification}s to the email channel.
 *
 * Called with the batch of notifications produced for one domain event (right
 * after they're flushed, alongside the Mercure publish). For each recipient
 * whose preferences ask for instant email of that type — and outside any quiet
 * hours — it sends one branded email deep-linking to the item.
 *
 * The stored `Notification.link` is relative and already audience-correct
 * (portal `/tickets/<id>` vs staff `/tasks`); we prefix it with the base URL
 * for the recipient's app (portal vs staff, decided by ROLE_PORTAL).
 *
 * Delivery is best-effort and never throws into the caller: the DB notification
 * row is the source of truth, and a mail failure must not abort the flush that
 * created it. Mail is queued async via Messenger, and the whole channel is
 * behind the default-deny {@see EgressModule::EmailOutbound} gate.
 */
final class NotificationEmailNotifier
{
    public function __construct(
        private readonly UserPreferencesRepository $preferences,
        private readonly MailerInterface $mailer,
        private readonly EgressGuard $egress,
        private readonly TranslatorInterface $translator,
        private readonly RecipientLocaleResolver $localeResolver,
        private readonly string $spaBaseUrl,
        private readonly string $portalBaseUrl,
        private readonly string $mailFrom,
        private readonly string $mailFromName = '',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param list<Notification> $notifications
     */
    public function onCreated(array $notifications): void
    {
        if ($notifications === [] || !$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return;
        }

        $now = new \DateTimeImmutable();
        foreach ($notifications as $notification) {
            try {
                $this->maybeSend($notification, $now);
            } catch (\Throwable $e) {
                $this->logger->warning('Notification email send failed: {message}', [
                    'message' => $e->getMessage(),
                    'notification' => $notification->getId()?->toRfc4122(),
                ]);
            }
        }
    }

    /**
     * Send one digest email to `$user` bundling `$notifications`. Returns false
     * if egress is denied, there's no address, or the list is empty. Preference
     * gating (who gets which cadence) is the caller's (digest command's) job —
     * this just renders and sends.
     *
     * @param list<Notification> $notifications
     */
    public function sendDigest(User $user, array $notifications): bool
    {
        if ($notifications === [] || !$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return false;
        }
        $to = $user->getEmail();
        if ($to === '') {
            return false;
        }

        $isPortal = \in_array('ROLE_PORTAL', $user->getRoles(), true);
        $base = $isPortal ? $this->portalBaseUrl : $this->spaBaseUrl;
        $items = array_map(fn (Notification $n) => [
            'title' => $n->getTitle(),
            'body' => $n->getBody(),
            'url' => rtrim($base, '/') . '/' . ltrim($n->getLink(), '/'),
            'occurredAt' => $n->getOccurredAt(),
        ], $notifications);

        $count = \count($items);
        // Rendered async (Messenger), so the locale travels in the context and
        // the templates apply it via the trans filter.
        $locale = $this->localeResolver->forUser($user);
        $fromName = $this->mailFromName !== '' ? $this->mailFromName : 'Worktide';
        $mail = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $fromName))
            ->to($to)
            // Single-notification subject is the notification's own title (content);
            // the multi subject is fixed chrome and gets localized.
            ->subject($count === 1 ? $items[0]['title'] : $this->translator->trans(
                'email.notification_digest.subject',
                ['%count%' => $count],
                null,
                $locale,
            ))
            ->htmlTemplate('email/notification_digest.html.twig')
            ->textTemplate('email/notification_digest.txt.twig')
            ->context([
                'firstName' => $user->getFirstName(),
                'items' => $items,
                'count' => $count,
                'locale' => $locale,
            ]);

        $this->mailer->send($mail);

        return true;
    }

    private function maybeSend(Notification $notification, \DateTimeImmutable $now): void
    {
        $recipient = $notification->getRecipient();
        $to = $recipient->getEmail();
        if ($to === '') {
            return;
        }

        $stored = $this->preferences->findOneByUser($recipient)?->getNotificationPreferences();
        $prefs = NotificationPreferences::fromArray($stored);

        $workspace = $notification->getWorkspace();
        $tz = new \DateTimeZone($workspace?->getTimezone() ?? 'Europe/Berlin');
        if (!$prefs->shouldSendInstant($notification->getType()->value, $now, $tz)) {
            return;
        }

        $isPortal = \in_array('ROLE_PORTAL', $recipient->getRoles(), true);
        $base = $isPortal ? $this->portalBaseUrl : $this->spaBaseUrl;
        $actionUrl = rtrim($base, '/') . '/' . ltrim($notification->getLink(), '/');

        // Rendered async (Messenger), so the locale travels in the context and
        // the templates apply it via the trans filter.
        $locale = $this->localeResolver->forUser($recipient, $workspace);

        $fromName = $this->mailFromName !== '' ? $this->mailFromName : 'Worktide';
        $mail = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $fromName))
            ->to($to)
            // Subject is the notification's own title (user content) — left as-is.
            ->subject($notification->getTitle())
            ->htmlTemplate('email/notification.html.twig')
            ->textTemplate('email/notification.txt.twig')
            ->context([
                'title' => $notification->getTitle(),
                'body' => $notification->getBody(),
                'actionUrl' => $actionUrl,
                'firstName' => $recipient->getFirstName(),
                'locale' => $locale,
            ]);

        $this->mailer->send($mail);
    }
}
