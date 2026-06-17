<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Webhook;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\PullNotSupportedException;
use App\Channels\Testable;
use App\Channels\TestResult;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Push-only inbound adapter for arbitrary third-party webhooks.
 *
 * The Channel.inboundConfig stores a single secret `token`; the
 * provider POSTs JSON (or form-encoded) to
 *   /v1/inbound/webhooks/{token}
 * and the WebhookIngestController resolves the Channel by token and
 * delegates to this adapter's consumeWebhook().
 *
 * This is the simplest non-email adapter and the **template** for
 * future provider-specific webhook adapters (Zabbix, Slack-Events-API,
 * Twilio inbound-SMS, Mailgun-bounces). Each of those is just this
 * shape with a custom payload-mapping; the auth + dispatch + dedup
 * infrastructure stays the same.
 *
 * Threading is intentionally NOT implemented — events from arbitrary
 * webhooks rarely have a notion of "thread" the adapter can recognise.
 * InboundEvent.conversation stays NULL; the AI tier (Phase D) decides
 * whether to group multiple alerts of the same kind.
 *
 * Idempotency: `X-Idempotency-Key` header takes priority, then
 * `id` / `uuid` / `eventId` in the JSON payload, then a hash of
 * (sender + body) as last resort. Same UNIQUE(channel, externalId)
 * constraint as every other inbound source — replays are no-ops.
 *
 * The Channel.inboundConfig shape:
 *   { token: string, expectedSender?: string }
 *
 * `token` is opaque; the SPA generates a 32-byte hex when the
 * channel is created. `expectedSender` is an optional hint shown in
 * the SPA — purely informational, not validated.
 */
final class GenericWebhookAdapter implements InboundAdapter, Testable
{
    public const CODE = 'webhook_generic';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundEventRepository $events,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Generischer Webhook (push-only)';
    }

    public function pull(Channel $channel): InboundResult
    {
        throw new PullNotSupportedException(
            'webhook_generic is push-only; events arrive at /v1/inbound/webhooks/{token}.'
        );
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        // Accept JSON, form-encoded, or raw text. JSON is the
        // expected shape but we don't enforce it — keeps the adapter
        // usable from senders that only send form data (legacy
        // monitoring tools, simple CRMs).
        $contentType = (string) ($request->headers->get('Content-Type') ?? '');
        $payload = [];
        $rawBody = (string) ($request->getContent() ?? '');
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($rawBody, true);
            $payload = is_array($decoded) ? $decoded : [];
        } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $payload = $request->request->all();
        }

        $externalId = $this->extractIdempotencyKey($request, $payload, $rawBody);
        if ($this->events->findByExternalId($channel, $externalId) !== null) {
            // Idempotent replay — still 2xx so the sender stops retrying.
            return InboundResult::empty();
        }

        $event = (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($externalId)
            ->setSenderRaw($this->extractSender($request, $payload))
            ->setSubject($this->extractSubject($payload))
            ->setBody($this->renderBody($payload, $rawBody))
            ->setReceivedAt(new \DateTimeImmutable())
            ->setSourceMetadata([
                'contentType' => $contentType,
                'remoteIp' => $request->getClientIp(),
                'userAgent' => $request->headers->get('User-Agent'),
                'headers' => $this->captureRelevantHeaders($request),
                'rawBody' => mb_substr($rawBody, 0, 64_000), // keep first 64 KB for AI / debug
            ]);

        $this->em->persist($event);
        // No threader — webhook events are standalone (conversationId stays NULL).

        return new InboundResult([$event], null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractIdempotencyKey(Request $request, array $payload, string $rawBody): string
    {
        $explicit = $request->headers->get('X-Idempotency-Key');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }
        foreach (['id', 'uuid', 'eventId', 'event_id', 'messageId'] as $k) {
            if (isset($payload[$k]) && is_scalar($payload[$k])) {
                return (string) $payload[$k];
            }
        }
        // Last resort: content hash. Collisions on identical replays
        // produce the same external id, so re-delivery still dedupes.
        return 'sha256:' . hash('sha256', $rawBody);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractSender(Request $request, array $payload): ?string
    {
        foreach (['from', 'sender', 'source', 'origin'] as $k) {
            if (isset($payload[$k]) && is_string($payload[$k])) {
                return mb_substr($payload[$k], 0, 200);
            }
        }
        $ua = $request->headers->get('User-Agent');
        return is_string($ua) ? mb_substr($ua, 0, 200) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractSubject(array $payload): ?string
    {
        foreach (['subject', 'title', 'summary', 'alertname', 'name', 'type'] as $k) {
            if (isset($payload[$k]) && is_scalar($payload[$k])) {
                return mb_substr((string) $payload[$k], 0, 250);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderBody(array $payload, string $rawBody): string
    {
        if ($payload === []) {
            // Form-encoded or text body with nothing parsed — keep raw.
            return $rawBody !== '' ? mb_substr($rawBody, 0, 16_000) : '(empty webhook payload)';
        }
        // Pretty-print the JSON so the SPA renders something readable
        // without bespoke per-provider templates. The AI tier can
        // still read the structured fields directly from sourceMetadata.
        $pretty = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        return mb_substr((string) $pretty, 0, 16_000);
    }

    /**
     * Capture headers that may carry provider hints (signatures,
     * event-types) without inhaling every header. Important: Authorization
     * is NEVER captured — even a webhook-token URL leak shouldn't
     * persist the token into another channel's source metadata.
     *
     * @return array<string, string>
     */
    private function captureRelevantHeaders(Request $request): array
    {
        $out = [];
        $whitelist = [
            'x-event-type', 'x-event-id', 'x-github-event', 'x-gitlab-event',
            'x-slack-signature', 'x-slack-request-timestamp',
            'x-zabbix-event', 'x-grafana-event',
            'x-idempotency-key',
        ];
        foreach ($whitelist as $h) {
            $v = $request->headers->get($h);
            if (is_string($v) && $v !== '') {
                $out[$h] = mb_substr($v, 0, 200);
            }
        }
        return $out;
    }

    public function selfTest(Channel $channel): TestResult
    {
        $token = (string) ($channel->getInboundConfig()['token'] ?? '');
        if ($token === '') {
            return TestResult::failed('Kein Token in inboundConfig — Webhook kann nicht empfangen.');
        }
        if (\strlen($token) < 16) {
            return TestResult::warning('Token ist sehr kurz; ein Angreifer könnte es bruteforcen. Empfehlung: 32+ Hex-Bytes.');
        }
        return TestResult::ok(
            'Webhook bereit. Sender muss POST auf {API}/v1/inbound/webhooks/' . $token . ' senden.',
            ['endpoint' => '/v1/inbound/webhooks/' . $token],
        );
    }
}
