<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Sipgate;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\OutboundAdapter;
use App\Channels\OutboundResult;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Entity\OutboundMessage;
use App\Repository\InboundEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * sipgate VoIP/Telefonie-Adapter. Inbound via Push-API-Webhooks
 * (newCall/answer/hangup), Outbound via REST API (calls, SMS).
 *
 * Channel.authConfig:
 *   - tokenId:  sipgate API token ID
 *   - token:    sipgate API token secret
 *
 * Channel.inboundConfig:
 *   - webhookToken: random token for the webhook URL
 *   - userId:       sipgate user ID (e.g. "w0") for SMS/call contexts
 *   - defaultAction: "dial" (default), "voicemail", "reject", "play"
 *   - playUrl:  URL of WAV file for Play action
 *   - dialTargets: array of E.164 numbers for Dial action
 *
 * Push-API webhook URL: POST /v1/channels/sipgate/webhook/{webhookToken}
 */
final class SipgateAdapter implements InboundAdapter, OutboundAdapter
{
    public const CODE = 'sipgate';
    private const API_BASE = 'https://api.sipgate.com/v2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly InboundEventRepository $events,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'sipgate (VoIP / SMS)';
    }

    // -- Inbound (Push API webhook) -------------------------------------------

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        $event = $request->request->get('event');
        $callId = $request->request->get('callId') ?? 'unknown';
        $from = $request->request->get('from') ?? '';
        $to = $request->request->get('to') ?? '';
        $direction = $request->request->get('direction') ?? '';
        $users = $request->request->all('user') ?? [];
        $cause = $request->request->get('cause') ?? '';

        $config = $channel->getInboundConfig() ?? [];
        $defaultAction = $config['defaultAction'] ?? 'dial';
        $dialTargets = (array) ($config['dialTargets'] ?? []);
        $playUrl = $config['playUrl'] ?? null;

        // Build the XML response sipgate expects
        $xml = $this->buildXmlResponse($event, $direction, $from, $defaultAction, $dialTargets, $playUrl);

        if ($event === 'newCall') {
            $externalId = 'sipgate-' . $callId;

            // If this callId already exists, skip (dedup)
            if ($this->events->findByExternalId($channel, $externalId) !== null) {
                return new InboundResult([], ['sipgateXml' => $xml]);
            }

            $subject = sprintf('%s call from %s', $direction === 'in' ? 'Incoming' : 'Outgoing', $from ?: 'unknown');
            if ($users !== []) {
                $subject .= sprintf(' for %s', implode(', ', $users));
            }

            $body = sprintf("**Direction:** %s\n**From:** %s\n**To:** %s\n**Users:** %s",
                $direction,
                $from ?: 'anonymous',
                $to ?: 'unknown',
                $users !== [] ? implode(', ', $users) : '—',
            );

            $event = (new InboundEvent())
                ->setWorkspace($channel->getWorkspace())
                ->setChannel($channel)
                ->setExternalId($externalId)
                ->setSenderRaw($from ?: 'sipgate')
                ->setSubject($this->trim($subject, 250))
                ->setBody($body)
                ->setReceivedAt(new \DateTimeImmutable('now'))
                ->setSourceMetadata([
                    'callId' => $callId,
                    'from' => $from,
                    'to' => $to,
                    'direction' => $direction,
                    'users' => $users,
                ]);

            $this->em->persist($event);

            return new InboundResult([$event], ['sipgateXml' => $xml]);
        }

        // answer / hangup — update existing event if possible
        if (($event === 'answer' || $event === 'hangup') && $callId !== 'unknown') {
            $externalId = 'sipgate-' . $callId;
            $existing = $this->events->findByExternalId($channel, $externalId);
            if ($existing !== null) {
                $body = $existing->getBody() ?? '';
                if ($event === 'answer') {
                    $answeringNumber = $request->request->get('answeringNumber') ?? '';
                    $body .= sprintf("\n\n**Answered** by %s", $answeringNumber ?: 'unknown');
                    $existing->setSubject($this->trim(str_replace('Incoming call', 'Answered call', $existing->getSubject() ?? ''), 250));
                } elseif ($event === 'hangup') {
                    $body .= sprintf("\n\n**Hung up** (%s)", $cause ?: 'unknown');
                    $existing->setSubject($this->trim(str_replace(['Incoming call', 'Outgoing call', 'Answered call'], 'Ended call', $existing->getSubject() ?? ''), 250));
                }
                $existing->setBody($body);
                $existing->setReceivedAt(new \DateTimeImmutable('now'));
            }
        }

        return new InboundResult([], ['sipgateXml' => $xml]);
    }

    public function pull(Channel $channel): InboundResult
    {
        // Pull history as a fallback via REST API
        $auth = $channel->getAuthConfig() ?? [];
        $tokenId = $auth['tokenId'] ?? '';
        $token = $auth['token'] ?? '';

        if ($tokenId === '' || $token === '') {
            return InboundResult::noop();
        }

        $config = $channel->getInboundConfig() ?? [];
        $userId = $config['userId'] ?? null;

        try {
            $response = $this->httpClient->request('GET', self::API_BASE . '/history', [
                'auth_basic' => [$tokenId, $token],
                'query' => ['limit' => 20, 'userId' => $userId],
                'timeout' => 30,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return InboundResult::noop();
        }

        $items = $data['items'] ?? [];
        $events = [];

        foreach ($items as $item) {
            $callId = $item['id'] ?? hash('sha256', json_encode($item) ?: 'sipgate-' . time());
            $externalId = 'sipgate-history-' . ($item['id'] ?? $callId);

            if ($this->events->findByExternalId($channel, $externalId) !== null) {
                continue;
            }

            $source = $item['source'] ?? 'unknown';
            $target = $item['target'] ?? 'unknown';
            $created = $item['created'] ?? (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);
            $type = $item['type'] ?? 'CALL';

            $subject = sprintf('[History] %s: %s → %s', $type, $source, $target);
            $body = sprintf("**Type:** %s\n**From:** %s\n**To:** %s\n**Date:** %s",
                $type, $source, $target, substr($created, 0, 19),
            );

            // Add direction-specific info
            $direction = $item['direction'] ?? '';
            if ($direction !== '') {
                $body .= sprintf("\n**Direction:** %s", $direction);
            }
            $status = $item['status'] ?? '';
            if ($status !== '') {
                $body .= sprintf("\n**Status:** %s", $status);
            }

            $event = (new InboundEvent())
                ->setWorkspace($channel->getWorkspace())
                ->setChannel($channel)
                ->setExternalId($externalId)
                ->setSenderRaw($source)
                ->setSubject($this->trim($subject, 250))
                ->setBody($body)
                ->setReceivedAt(new \DateTimeImmutable($created))
                ->setSourceMetadata($item);

            $this->em->persist($event);
            $events[] = $event;
        }

        return $events === [] ? InboundResult::noop() : InboundResult::events($events);
    }

    // -- Outbound (REST API) -------------------------------------------------

    public function send(Channel $channel, OutboundMessage $message): OutboundResult
    {
        $auth = $channel->getAuthConfig() ?? [];
        $tokenId = $auth['tokenId'] ?? '';
        $token = $auth['token'] ?? '';

        if ($tokenId === '' || $token === '') {
            return OutboundResult::fail('sipgate requires tokenId and token in authConfig.');
        }

        $config = $channel->getInboundConfig() ?? [];
        $userId = $config['userId'] ?? null;

        $meta = $message->getOutboundMetadata() ?? [];
        $kind = $meta['sipgateKind'] ?? 'call';

        try {
            if ($kind === 'sms') {
                $to = $meta['to'] ?? '';
                if ($to === '') {
                    return OutboundResult::fail('SMS requires "to" in outboundMetadata.');
                }

                $body = strip_tags($message->getBody() ?? '');

                $response = $this->httpClient->request('POST', self::API_BASE . '/' . $userId . '/sms', [
                    'auth_basic' => [$tokenId, $token],
                    'json' => ['smsId' => uniqid('wt-', true), 'recipient' => $to, 'text' => $body],
                    'timeout' => 30,
                ]);

                $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
                return OutboundResult::sent($data['smsId'] ?? 'sent');
            }

            // Default: initiate call
            $callerId = $meta['callerId'] ?? $config['callerId'] ?? null;
            $to = $meta['to'] ?? $message->getRecipient() ?? '';

            if ($to === '') {
                return OutboundResult::fail('Call requires "to" in outboundMetadata.');
            }

            $payload = [
                'callerId' => $callerId,
                'callee' => $to,
                'caller' => $userId,
                'deviceId' => $meta['deviceId'] ?? null,
            ];

            $response = $this->httpClient->request('POST', self::API_BASE . '/sessions/calls', [
                'auth_basic' => [$tokenId, $token],
                'json' => $payload,
                'timeout' => 30,
            ]);

            $data = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            return OutboundResult::sent($data['sessionId'] ?? 'initiated');
        } catch (\Throwable $e) {
            return OutboundResult::fail($e->getMessage());
        }
    }

    // -- Helpers --------------------------------------------------------------

    private function buildXmlResponse(string $event, string $direction, string $from, string $defaultAction, array $dialTargets, ?string $playUrl): string
    {
        if ($direction !== 'in') {
            return '<Response />';
        }

        // Block unwanted callers
        if (str_contains($from, 'anonymous')) {
            return "<Response>\n  <Reject />\n</Response>";
        }

        return match ($defaultAction) {
            'reject' => "<Response>\n  <Reject />\n</Response>",
            'voicemail' => "<Response>\n  <Dial>\n    <Voicemail />\n  </Dial>\n</Response>",
            'play' => $playUrl !== null
                ? "<Response>\n  <Play>\n    <Url>" . htmlspecialchars($playUrl, ENT_XML1) . "</Url>\n  </Play>\n</Response>"
                : "<Response>\n  <Dial>\n    <Number>" . htmlspecialchars($dialTargets[0] ?? '', ENT_XML1) . "</Number>\n  </Dial>\n</Response>",
            default => $dialTargets !== []
                ? "<Response>\n  <Dial>\n" . implode("\n", array_map(static fn (string $n): string => "    <Number>" . htmlspecialchars($n, ENT_XML1) . "</Number>", $dialTargets)) . "\n  </Dial>\n</Response>"
                : "<Response>\n  <Reject reason=\"busy\" />\n</Response>",
        };
    }

    private function trim(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . "\u{2026}" : $s;
    }
}
