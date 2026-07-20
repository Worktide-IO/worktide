<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\InboundEvent;
use App\Message\DispatchAutomationEventMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers one processed {@see InboundEvent} to the external automation engine
 * (self-hosted n8n) via its webhook-trigger URL, so a workflow there can decide
 * what happens with the data (tag/route/escalate the conversation, kick off a
 * marketing sequence, …).
 *
 * Fire-and-forget notification, not a webhook subscription: the target is a
 * single operator-configured, TRUSTED, usually-internal endpoint (dev:
 * http://n8n:5678). That is why the public-URL SSRF guard is deliberately NOT
 * applied here — n8n lives on a private host that the guard would reject; the
 * `automation` egress module + the fixed config URL are the control instead.
 *
 * The body is compact JSON, optionally HMAC-SHA256-signed with N8N_WEBHOOK_SECRET
 * (X-Worktide-Signature: sha256=<hex>) so the workflow can verify authenticity.
 * Non-2xx / network errors are re-thrown so Messenger's retry strategy applies;
 * a missing row or unconfigured feature settle cleanly without a retry.
 */
#[AsMessageHandler]
final class DispatchAutomationEventHandler
{
    private const TIMEOUT_SECONDS = 10;
    private const SIGNATURE_HEADER = 'X-Worktide-Signature';
    private const EVENT_HEADER = 'X-Worktide-Event';
    private const EVENT_NAME = 'inbound.received';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly EgressGuard $egress,
        private readonly string $webhookUrl,
        private readonly string $webhookSecret,
    ) {}

    public function __invoke(DispatchAutomationEventMessage $msg): void
    {
        if (trim($this->webhookUrl) === '') {
            return; // feature off — nothing wired
        }

        $event = $this->em->find(InboundEvent::class, $msg->getInboundEventId());
        if ($event === null) {
            throw new UnrecoverableMessageHandlingException(sprintf(
                'InboundEvent %s no longer exists; dropping automation dispatch.',
                $msg->getInboundEventId()->toRfc4122(),
            ));
        }

        // Default-deny egress gate: withhold unless the automation module is
        // approved (EGRESS_ALLOW). Skip cleanly — no failed attempt, no retry.
        if (!$this->egress->isAllowed(EgressModule::Automation)) {
            $this->logger->info('Withholding automation dispatch — egress module not approved.', [
                'inboundEventId' => $event->getId()?->toRfc4122(),
                'channel' => $event->getChannel()->getAdapterCode(),
            ]);

            return;
        }

        $body = json_encode($this->payload($event), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new UnrecoverableMessageHandlingException('Automation payload not JSON-encodable.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Worktide-Automation/1.0',
            self::EVENT_HEADER => self::EVENT_NAME,
        ];
        if (trim($this->webhookSecret) !== '') {
            $headers[self::SIGNATURE_HEADER] = 'sha256=' . hash_hmac('sha256', $body, $this->webhookSecret);
        }

        try {
            $response = $this->http->request('POST', $this->webhookUrl, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                $this->logger->info('Dispatched inbound event to automation engine.', [
                    'inboundEventId' => $event->getId()?->toRfc4122(),
                    'httpStatus' => $code,
                ]);

                return;
            }
            // Non-2xx is recoverable (n8n restart, transient 5xx) → let Messenger retry.
            throw new \RuntimeException(sprintf('Automation engine returned HTTP %d.', $code));
        } catch (HttpClientException $e) {
            $this->logger->warning('Automation dispatch failed; will retry.', [
                'inboundEventId' => $event->getId()?->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(InboundEvent $event): array
    {
        $channel = $event->getChannel();
        $conversation = $event->getConversation();

        return [
            'event' => self::EVENT_NAME,
            'inboundEventId' => $event->getId()?->toRfc4122(),
            'externalId' => $event->getExternalId(),
            'receivedAt' => $event->getReceivedAt()->format(\DateTimeInterface::ATOM),
            'workspaceId' => $channel->getWorkspace()->getId()?->toRfc4122(),
            'channel' => [
                'id' => $channel->getId()?->toRfc4122(),
                'adapter' => $channel->getAdapterCode(),
                'name' => $channel->getName(),
            ],
            'senderRaw' => $event->getSenderRaw(),
            'subject' => $event->getSubject(),
            'body' => $event->getBody(),
            'sourceMetadata' => $event->getSourceMetadata(),
            'conversationId' => $conversation?->getId()?->toRfc4122(),
            'customerId' => $conversation?->getCustomer()?->getId()?->toRfc4122(),
        ];
    }
}
