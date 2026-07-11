<?php

declare(strict_types=1);

namespace App\Notification\Chat;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\UserChatWebhook;
use App\Http\OutboundUrlGuard;
use App\Http\UnsafeUrlException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts one rendered notification to a user's chat webhook. Shared by the async
 * {@see \App\MessageHandler\SendChatNotificationHandler} and the "send test"
 * action so both get identical egress gating + SSRF pinning. Best-effort:
 * returns success/failure, never throws.
 *
 * Behind the default-deny {@see EgressModule::ChatOutbound} gate; the webhook URL
 * is validated as a public http(s) target and the connection is pinned to the
 * resolved IP (kills DNS-rebinding), redirects disabled — same as outbound
 * HMAC webhooks.
 */
final class ChatWebhookSender
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EgressGuard $egress,
        private readonly OutboundUrlGuard $urlGuard,
        private readonly ChatPayloadBuilder $payloads,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function send(UserChatWebhook $webhook, string $title, ?string $body, string $actionUrl): bool
    {
        if (!$this->egress->isAllowed(EgressModule::ChatOutbound)) {
            return false;
        }

        try {
            $target = $this->urlGuard->assertPublicHttpUrl($webhook->getUrl());
        } catch (UnsafeUrlException $e) {
            $this->logger->warning('Chat webhook refused (unsafe URL): {message}', ['message' => $e->getMessage()]);

            return false;
        }

        $payload = $this->payloads->build($webhook->getProvider(), $title, $body, $actionUrl);

        try {
            $code = $this->http->request('POST', $webhook->getUrl(), [
                'headers' => ['Content-Type' => 'application/json', 'User-Agent' => 'Worktide-Chat/1.0'],
                'json' => $payload,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
                'resolve' => [$target['host'] => $target['ip']],
            ])->getStatusCode();

            if ($code >= 200 && $code < 300) {
                return true;
            }
            $this->logger->warning('Chat webhook delivery failed: HTTP {code}', ['code' => $code]);
        } catch (\Throwable $e) {
            $this->logger->warning('Chat webhook delivery error: {message}', ['message' => $e->getMessage()]);
        }

        return false;
    }
}
