<?php

declare(strict_types=1);

namespace App\Channels\Adapter\EmailGraph;

use App\Channels\OAuth\OAuth2Client;
use App\Entity\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Manages Microsoft Graph change-notification subscriptions for `email_graph`
 * channels — the push counterpart to {@see EmailGraphAdapter::pull()}.
 *
 * Graph delivers near-realtime notifications to our public webhook endpoint
 * ({publicBase}/v1/inbound/webhooks/{token}) whenever a message is created in
 * the subscribed mailbox; {@see \App\Controller\Api\WebhookIngestController}
 * then routes them into {@see EmailGraphAdapter::consumeWebhook()}.
 *
 * Subscriptions on message resources expire after ~4230 minutes, so they must
 * be renewed on a schedule (see MailboxGraphSubscriptionsCommand). Subscription
 * state (id + clientState secret + expiry) lives under
 * `authConfig['graphSubscription']`, encrypted at rest by
 * {@see \App\EventSubscriber\ChannelAuthConfigCipherListener}.
 */
final class GraphSubscriptionManager
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    /** Graph caps message-resource subscriptions at 4230 min; stay just under. */
    private const LIFETIME_MINUTES = 4200;
    /** Renew when the live subscription expires within this window. */
    public const RENEW_THRESHOLD_MINUTES = 12 * 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly OAuth2Client $oauth,
        private readonly string $publicBase,
    ) {}

    /**
     * Reconcile a channel to a live subscription: create if missing, renew if
     * expiring soon, otherwise leave it. Persists on change.
     */
    public function ensureSubscription(Channel $channel): void
    {
        $sub = $this->currentSubscription($channel);
        if (($sub['subscriptionId'] ?? '') === '') {
            $this->subscribe($channel);

            return;
        }
        if ($this->expiresWithin($sub, self::RENEW_THRESHOLD_MINUTES)) {
            $this->renew($channel);
        }
    }

    public function subscribe(Channel $channel): void
    {
        $token = (string) ($channel->getInboundConfig()['token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('Channel has no inbound webhook token — cannot subscribe.');
        }

        $clientState = bin2hex(random_bytes(24));
        $body = [
            'changeType' => 'created',
            'notificationUrl' => rtrim($this->publicBase, '/') . '/v1/inbound/webhooks/' . $token,
            'resource' => $this->resource($channel),
            'expirationDateTime' => $this->expiry(),
            'clientState' => $clientState,
        ];

        $data = $this->request('POST', self::GRAPH_BASE . '/subscriptions', $channel, $body);

        $this->store($channel, [
            'subscriptionId' => (string) ($data['id'] ?? ''),
            'clientState' => $clientState,
            'expiresAt' => (string) ($data['expirationDateTime'] ?? $this->expiry()),
        ]);
    }

    public function renew(Channel $channel): void
    {
        $sub = $this->currentSubscription($channel);
        $id = (string) ($sub['subscriptionId'] ?? '');
        if ($id === '') {
            $this->subscribe($channel);

            return;
        }

        try {
            $data = $this->request(
                'PATCH',
                self::GRAPH_BASE . '/subscriptions/' . rawurlencode($id),
                $channel,
                ['expirationDateTime' => $this->expiry()],
            );
        } catch (GraphSubscriptionGoneException) {
            // Expired / deleted upstream — recreate from scratch.
            $this->subscribe($channel);

            return;
        }

        $sub['expiresAt'] = (string) ($data['expirationDateTime'] ?? $this->expiry());
        $this->store($channel, $sub);
    }

    /** Best-effort teardown; always clears local state even if the DELETE fails. */
    public function unsubscribe(Channel $channel): void
    {
        $id = (string) ($this->currentSubscription($channel)['subscriptionId'] ?? '');
        if ($id !== '') {
            try {
                $this->request('DELETE', self::GRAPH_BASE . '/subscriptions/' . rawurlencode($id), $channel, null);
            } catch (\Throwable) {
                // ignore — the subscription lapses on its own at expiry
            }
        }
        $auth = $channel->getAuthConfig();
        unset($auth['graphSubscription']);
        $channel->setAuthConfig($auth);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function currentSubscription(Channel $channel): array
    {
        $sub = $channel->getAuthConfig()['graphSubscription'] ?? [];

        return is_array($sub) ? $sub : [];
    }

    /** @param array<string, mixed> $sub */
    private function expiresWithin(array $sub, int $minutes): bool
    {
        $expiresAt = (string) ($sub['expiresAt'] ?? '');
        if ($expiresAt === '') {
            return true;
        }
        try {
            $when = new \DateTimeImmutable($expiresAt);
        } catch (\Exception) {
            return true;
        }

        return $when->getTimestamp() - time() <= $minutes * 60;
    }

    /** @param array<string, mixed> $sub */
    private function store(Channel $channel, array $sub): void
    {
        $auth = $channel->getAuthConfig();
        $auth['graphSubscription'] = $sub;
        $channel->setAuthConfig($auth);
        $this->em->flush();
    }

    private function resource(Channel $channel): string
    {
        $cfg = $channel->getInboundConfig();
        $folder = (string) ($cfg['folder'] ?? 'Inbox');
        $mailboxUser = (string) ($cfg['mailboxUser'] ?? 'me');
        $base = $mailboxUser === 'me' ? 'me' : 'users/' . $mailboxUser;

        return sprintf("%s/mailFolders('%s')/messages", $base, $folder);
    }

    private function expiry(): string
    {
        return (new \DateTimeImmutable('+' . self::LIFETIME_MINUTES . ' minutes'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, Channel $channel, ?array $json): array
    {
        $accessToken = $this->oauth->ensureAccessToken($channel);
        $options = ['headers' => ['Authorization' => 'Bearer ' . $accessToken]];
        if ($json !== null) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            if ($status === 404 || $status === 410) {
                throw new GraphSubscriptionGoneException();
            }
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('Graph subscription %s %s failed: HTTP %d', $method, $url, $status));
            }
            // DELETE returns 204 with no body.
            if ($status === 204) {
                return [];
            }

            return $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Graph subscription request failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
