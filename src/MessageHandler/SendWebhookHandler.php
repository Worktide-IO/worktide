<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Enum\WebhookDeliveryStatus;
use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Http\OutboundUrlGuard;
use App\Http\UnsafeUrlException;
use App\Message\SendWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends one outbound webhook request and records the outcome.
 *
 * Body is canonical JSON (compact, sorted-key-stable order from the
 * SendWebhookMessage payload); the same string is HMAC-SHA256-signed with the
 * webhook secret. The signature header format is:
 *
 *   X-Worktide-Signature: sha256=<hex digest>
 *
 * 2xx response → status=success, failureCount reset to 0, lastSucceededAt set.
 * Anything else (non-2xx, exception, timeout) → status=failure, failureCount++.
 * On reaching {@see Webhook::FAILURE_THRESHOLD} the hook auto-deactivates.
 *
 * Recoverable failures (non-2xx + network errors) are re-thrown so the
 * Messenger transport's retry strategy (config/packages/messenger.yaml,
 * `async.retry_strategy`) kicks in. Unrecoverable failures (missing webhook,
 * bad URL) throw UnrecoverableMessageHandlingException so they go straight to
 * the failed transport.
 */
#[AsMessageHandler]
final class SendWebhookHandler
{
    private const TIMEOUT_SECONDS = 10;
    private const SIGNATURE_HEADER = 'X-Worktide-Signature';
    private const EVENT_HEADER = 'X-Worktide-Event';
    private const DELIVERY_HEADER = 'X-Worktide-Delivery';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly EgressGuard $egress,
        private readonly OutboundUrlGuard $urlGuard,
    ) {}

    public function __invoke(SendWebhookMessage $msg): void
    {
        $webhook = $this->em->find(Webhook::class, $msg->getWebhookId());
        if ($webhook === null) {
            throw new UnrecoverableMessageHandlingException(sprintf(
                'Webhook %s no longer exists; dropping delivery.',
                $msg->getWebhookId()->toRfc4122(),
            ));
        }
        if (!$webhook->isActive()) {
            // Disabled mid-flight — log and skip silently. No delivery row,
            // since "we never tried" reads better than "tried and failed".
            $this->logger->info('Skipping webhook delivery — subscription inactive.', [
                'webhookId' => $webhook->getId()?->toRfc4122(),
                'event' => $msg->getEventPayload()['name'] ?? '?',
            ]);
            return;
        }
        // Default-deny egress gate: withhold delivery unless the webhook_delivery
        // module is approved (EGRESS_ALLOW). Skip cleanly — no failed attempt.
        if (!$this->egress->isAllowed(EgressModule::WebhookDelivery)) {
            $this->logger->info('Withholding webhook delivery — egress module not approved.', [
                'webhookId' => $webhook->getId()?->toRfc4122(),
                'event' => $msg->getEventPayload()['name'] ?? '?',
            ]);
            return;
        }

        $delivery = (new WebhookDelivery())
            ->setWebhook($webhook)
            ->setEventName((string) ($msg->getEventPayload()['name'] ?? 'unknown'))
            ->setAttempt($msg->getAttempt())
            ->setStatus(WebhookDeliveryStatus::Pending);

        $eventIdRaw = $msg->getEventPayload()['aggregateId'] ?? null;
        if (\is_string($eventIdRaw) && $eventIdRaw !== '' && Uuid::isValid($eventIdRaw)) {
            $delivery->setEventId(Uuid::fromString($eventIdRaw));
        }

        $body = json_encode($msg->getEventPayload(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $delivery
                ->setStatus(WebhookDeliveryStatus::Failure)
                ->setErrorMessage('payload not JSON-encodable')
                ->setAttemptedAt(new \DateTimeImmutable());
            $this->em->persist($delivery);
            $webhook->setLastFailedAt(new \DateTimeImmutable());
            $this->em->flush();
            throw new UnrecoverableMessageHandlingException('Webhook payload not JSON-encodable.');
        }
        $signature = 'sha256=' . hash_hmac('sha256', $body, $webhook->getSecret());

        $startedAt = microtime(true);
        $delivery->setAttemptedAt(new \DateTimeImmutable());
        $webhook->setLastTriggeredAt($delivery->getAttemptedAt());

        // SSRF guard: the target URL is operator-supplied. Refuse anything that
        // isn't a public http(s) host and pin the connection to the validated
        // IP (defeats DNS rebinding between here and the request).
        try {
            $target = $this->urlGuard->assertPublicHttpUrl($webhook->getUrl());
        } catch (UnsafeUrlException $e) {
            $delivery
                ->setStatus(WebhookDeliveryStatus::Failure)
                ->setErrorMessage('Refused (unsafe URL): ' . $e->getMessage())
                ->setDurationMs(0);
            $this->registerFailure($webhook);
            $this->em->persist($delivery);
            $this->em->flush();
            // Bad target won't fix itself on retry → straight to the failed transport.
            throw new UnrecoverableMessageHandlingException('Webhook URL is not a safe public target: ' . $e->getMessage());
        }

        try {
            $response = $this->http->request('POST', $webhook->getUrl(), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Worktide-Webhook/1.0',
                    self::SIGNATURE_HEADER => $signature,
                    self::EVENT_HEADER => $msg->getEventPayload()['name'] ?? '',
                    self::DELIVERY_HEADER => $msg->getEventPayload()['id'] ?? '',
                ],
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                // Pin DNS to the validated IP and never follow redirects — a 3xx
                // to an internal URL would otherwise bypass the SSRF guard.
                'max_redirects' => 0,
                'resolve' => [$target['host'] => $target['ip']],
            ]);
            $code = $response->getStatusCode();
            $responseBody = $response->getContent(throw: false);
            $delivery
                ->setHttpStatus($code)
                ->setResponseBody($responseBody)
                ->setDurationMs((int) round((microtime(true) - $startedAt) * 1000));

            if ($code >= 200 && $code < 300) {
                $delivery->setStatus(WebhookDeliveryStatus::Success);
                $webhook
                    ->setFailureCount(0)
                    ->setLastSucceededAt(new \DateTimeImmutable());
                $this->em->persist($delivery);
                $this->em->flush();
                return;
            }

            $delivery
                ->setStatus(WebhookDeliveryStatus::Failure)
                ->setErrorMessage(sprintf('HTTP %d', $code));
            $this->registerFailure($webhook);
            $this->em->persist($delivery);
            $this->em->flush();
            throw new \RuntimeException(sprintf('Webhook returned HTTP %d', $code));
        } catch (HttpClientException $e) {
            $delivery
                ->setStatus(WebhookDeliveryStatus::Failure)
                ->setErrorMessage($e->getMessage())
                ->setDurationMs((int) round((microtime(true) - $startedAt) * 1000));
            $this->registerFailure($webhook);
            $this->em->persist($delivery);
            $this->em->flush();
            throw $e;
        }
    }

    private function registerFailure(Webhook $webhook): void
    {
        $webhook
            ->setFailureCount($webhook->getFailureCount() + 1)
            ->setLastFailedAt(new \DateTimeImmutable());
        if ($webhook->getFailureCount() >= Webhook::FAILURE_THRESHOLD) {
            $webhook->setIsActive(false);
            $this->logger->warning('Auto-deactivated webhook after consecutive failures.', [
                'webhookId' => $webhook->getId()?->toRfc4122(),
                'failureCount' => $webhook->getFailureCount(),
            ]);
        }
    }
}
