<?php

declare(strict_types=1);

namespace App\Notification;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Notification;
use App\Message\NotifyChatMessage;
use App\Notification\Preference\NotificationPreferences;
use App\Repository\UserChatWebhookRepository;
use App\Repository\UserPreferencesRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Bridges freshly-created {@see Notification}s to the chat channel (Slack /
 * Mattermost / Teams), parallel to {@see NotificationEmailNotifier}.
 *
 * For each recipient whose prefs enable chat for that type — outside quiet hours,
 * with an enabled webhook configured — it fans out one async {@see NotifyChatMessage}
 * (the HTTP POST happens in the handler, off the flush). Chat is instant-only:
 * there's no chat digest. Behind the default-deny {@see EgressModule::ChatOutbound}
 * gate; never throws into the caller (the DB notification row is the truth).
 *
 * The stored `Notification.link` is relative + audience-correct, so we prefix it
 * with the recipient's app base (portal vs staff, by ROLE_PORTAL) — same as email.
 */
final class NotificationChatNotifier
{
    public function __construct(
        private readonly UserPreferencesRepository $preferences,
        private readonly UserChatWebhookRepository $webhooks,
        private readonly EgressGuard $egress,
        private readonly MessageBusInterface $bus,
        private readonly string $spaBaseUrl,
        private readonly string $portalBaseUrl,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param list<Notification> $notifications
     */
    public function onCreated(array $notifications): void
    {
        if ($notifications === [] || !$this->egress->isAllowed(EgressModule::ChatOutbound)) {
            return;
        }

        $now = new \DateTimeImmutable();
        foreach ($notifications as $notification) {
            try {
                $this->maybeDispatch($notification, $now);
            } catch (\Throwable $e) {
                $this->logger->warning('Chat notification dispatch failed: {message}', ['message' => $e->getMessage()]);
            }
        }
    }

    private function maybeDispatch(Notification $notification, \DateTimeImmutable $now): void
    {
        $recipient = $notification->getRecipient();

        $webhook = $this->webhooks->findOneByUser($recipient);
        if ($webhook === null || !$webhook->isEnabled()) {
            return;
        }

        $stored = $this->preferences->findOneByUser($recipient)?->getNotificationPreferences();
        $prefs = NotificationPreferences::fromArray($stored);
        $tz = new \DateTimeZone($notification->getWorkspace()?->getTimezone() ?? 'Europe/Berlin');
        if (!$prefs->shouldSendChat($notification->getType()->value, $now, $tz)) {
            return;
        }

        $isPortal = \in_array('ROLE_PORTAL', $recipient->getRoles(), true);
        $base = $isPortal ? $this->portalBaseUrl : $this->spaBaseUrl;
        $actionUrl = rtrim($base, '/') . '/' . ltrim($notification->getLink(), '/');

        $webhookId = $webhook->getId();
        if ($webhookId !== null) {
            $this->bus->dispatch(new NotifyChatMessage($webhookId, $notification->getTitle(), $notification->getBody(), $actionUrl));
        }
    }
}
