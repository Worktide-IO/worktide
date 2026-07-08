<?php

declare(strict_types=1);

namespace App\Channels\Adapter\EmailGraph;

use App\Channels\Adapter\Email\MailThreader;
use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\OAuth\OAuth2Client;
use App\Channels\OAuth\OAuth2TokenException;
use App\Channels\OutboundAdapter;
use App\Channels\OutboundResult;
use App\Channels\Testable;
use App\Channels\TestResult;
use App\Entity\Channel;
use App\Entity\Contact;
use App\Entity\Enum\InboundEventState;
use App\Entity\InboundEvent;
use App\Entity\OutboundMessage;
use App\Repository\ContactRepository;
use App\Repository\InboundEventRepository;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Microsoft 365 / Exchange Online channel via Microsoft Graph v1.0.
 *
 * Inbound: incremental pull using Graph delta-queries
 *   GET /me/mailFolders/{folder}/messages/delta
 * The delta-link from the previous run is stashed in
 * Channel.inboundConfig['cursor'] so each call only sees mails new
 * since the previous one.
 *
 * Outbound: POST /me/sendMail with a structured Message object.
 * Threading: `inReplyTo` event's Message-ID is added as
 * `internetMessageHeaders` so the recipient sees a stitched thread.
 *
 * Hardening (same shape as EmailImapAdapter, C.3):
 *   - Pre-check `size` on the message-list response before fetching
 *     the body of any individual message that exceeds
 *     `maxMessageBytes`.
 *   - Body truncation + FileStorage offload for inline excerpts >
 *     `maxInlineBodyBytes`.
 *   - Attachments via separate Graph endpoint, always to FileStorage.
 *   - Outbound size pre-check before hitting `/sendMail`.
 *
 * Auth: bearer token from {@see OAuth2Client::ensureAccessToken()},
 * refreshed in-place when within 60 s of expiry.
 */
final class EmailGraphAdapter implements InboundAdapter, OutboundAdapter, Testable
{
    public const CODE = 'email_graph';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private const DEFAULT_MAX_MESSAGE_BYTES = 25 * 1024 * 1024;
    private const DEFAULT_MAX_INLINE_BODY_BYTES = 256 * 1024;
    private const DEFAULT_MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;
    private const DEFAULT_MAX_OUTBOUND_BYTES = 25 * 1024 * 1024;
    private const DEFAULT_PULL_BATCH_LIMIT = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly OAuth2Client $oauth,
        private readonly InboundEventRepository $events,
        private readonly ContactRepository $contacts,
        private readonly MailThreader $threader,
        private readonly FileStorage $fileStorage,
    ) {}

    public function getCode(): string { return self::CODE; }
    public function getLabel(): string { return 'E-Mail (Microsoft 365 / Graph)'; }

    // ---- inbound ---------------------------------------------------

    public function pull(Channel $channel): InboundResult
    {
        $accessToken = $this->oauth->ensureAccessToken($channel);
        $cfg = $channel->getInboundConfig();
        $maxBytes = (int) ($cfg['maxMessageBytes'] ?? self::DEFAULT_MAX_MESSAGE_BYTES);
        $batchLimit = (int) ($cfg['pullBatchLimit'] ?? self::DEFAULT_PULL_BATCH_LIMIT);
        $folder = (string) ($cfg['folder'] ?? 'Inbox');
        $mailboxUser = (string) ($cfg['mailboxUser'] ?? 'me');

        // The cursor from a previous run is a full Graph delta-link URL.
        // First run: build the seed endpoint from the channel config.
        $cursor = $cfg['cursor'] ?? null;
        $url = is_string($cursor) && $cursor !== ''
            ? $cursor
            : $this->graphUrl(sprintf(
                '/%s/mailFolders/%s/messages/delta',
                $mailboxUser === 'me' ? 'me' : 'users/' . rawurlencode($mailboxUser),
                rawurlencode($folder),
            )) . '?$top=' . $batchLimit . '&$select=id,internetMessageId,subject,from,toRecipients,sentDateTime,receivedDateTime,internetMessageHeaders,bodyPreview,size,hasAttachments';

        $newEvents = [];
        $nextCursor = $cursor;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]);
            $body = $response->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new OAuth2TokenException('Graph request failed: ' . $e->getMessage(), 0, $e);
        }

        foreach (($body['value'] ?? []) as $msg) {
            if (!is_array($msg)) continue;
            $messageId = (string) ($msg['internetMessageId'] ?? '');
            if ($messageId === '') {
                // Synthesize a stable ID for the rare provider-only-id case.
                $messageId = 'graph-' . ($msg['id'] ?? bin2hex(random_bytes(8))) . '@graph.local';
            }
            if ($this->events->findByExternalId($channel, $messageId) !== null) {
                continue;
            }
            $size = (int) ($msg['size'] ?? 0);
            if ($size > $maxBytes) {
                $event = $this->buildOversizedStub($channel, $msg, $messageId, $size, $maxBytes);
                $this->em->persist($event);
                $this->threader->attach($channel, $event);
                $newEvents[] = $event;
                continue;
            }
            $event = $this->buildEvent($channel, $msg, $messageId, $accessToken);
            $this->em->persist($event);
            $this->threader->attach($channel, $event);
            $newEvents[] = $event;
        }

        // Graph delta: `@odata.deltaLink` on the final page, else
        // `@odata.nextLink` for the next batch. Persist whichever is
        // present so the next pull resumes correctly.
        $nextCursor = (string) ($body['@odata.deltaLink'] ?? $body['@odata.nextLink'] ?? $cursor ?? '');

        return new InboundResult($newEvents, $nextCursor);
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        // The subscription-validation handshake (?validationToken=) is answered
        // generically by WebhookIngestController before we get here — this only
        // ever sees real change notifications:
        //   { "value": [ { "subscriptionId", "clientState", "resource", … } ] }
        $payload = json_decode((string) $request->getContent(), true);
        $notifications = is_array($payload) && is_array($payload['value'] ?? null)
            ? $payload['value']
            : [];
        if ($notifications === []) {
            return InboundResult::empty();
        }

        // Verify clientState on EVERY notification against the secret stashed at
        // subscribe-time. A single mismatch (spoofed / stale subscription) makes
        // us ignore the whole batch rather than fetch — cheap and fail-closed.
        $expected = (string) ($channel->getAuthConfig()['graphSubscription']['clientState'] ?? '');
        if ($expected === '') {
            return InboundResult::empty();
        }
        foreach ($notifications as $n) {
            $got = is_array($n) ? (string) ($n['clientState'] ?? '') : '';
            if (!hash_equals($expected, $got)) {
                return InboundResult::empty();
            }
        }

        // A notification only says "something changed in the mailbox". Reuse the
        // delta pull to fetch exactly the new messages (dedup + threading + size
        // hardening all come for free), then persist the advanced delta cursor —
        // WebhookIngestController flushes but, unlike ChannelPullCommand, does not
        // write the cursor back, so we do it here.
        $result = $this->pull($channel);
        if ($result->cursor !== null && $result->cursor !== '') {
            $cfg = $channel->getInboundConfig();
            $cfg['cursor'] = $result->cursor;
            $channel->setInboundConfig($cfg);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $msg
     */
    private function buildEvent(Channel $channel, array $msg, string $messageId, string $accessToken): InboundEvent
    {
        $cfg = $channel->getInboundConfig();
        $maxInline = (int) ($cfg['maxInlineBodyBytes'] ?? self::DEFAULT_MAX_INLINE_BODY_BYTES);
        $maxAttBytes = (int) ($cfg['maxAttachmentBytes'] ?? self::DEFAULT_MAX_ATTACHMENT_BYTES);

        $from = $msg['from']['emailAddress'] ?? null;
        $fromAddr = is_array($from) ? (string) ($from['address'] ?? '') : '';
        $fromName = is_array($from) ? (string) ($from['name'] ?? '') : '';
        $senderRaw = $fromName !== '' ? "$fromName <$fromAddr>" : ($fromAddr ?: null);

        $subject = (string) ($msg['subject'] ?? '');
        $receivedAt = isset($msg['receivedDateTime'])
            ? new \DateTimeImmutable((string) $msg['receivedDateTime'])
            : new \DateTimeImmutable();

        // Body: Graph delta doesn't include the body in the default
        // delta selector. Pull on-demand from the message endpoint
        // (cheaper than $select=body in the delta because most mails
        // are dismissed at the size pre-check above anyway).
        $bodyText = $this->fetchBodyText($accessToken, (string) ($msg['id'] ?? ''));
        $bodyTruncated = false;
        $fullBodyPath = null;
        if (\strlen($bodyText) > $maxInline) {
            $written = $this->fileStorage->writeBytes(
                $bodyText,
                $channel->getWorkspace(),
                Uuid::v7(),
                Uuid::v7(),
                'mail-body.txt',
            );
            $fullBodyPath = $written['path'];
            $bodyText = mb_strcut($bodyText, 0, $maxInline) . "\n\n[... body truncated; full body stored as file ...]";
            $bodyTruncated = true;
        }

        // Attachments — fetched only when the flag says so. Graph
        // returns base64-encoded contentBytes for fileAttachment.
        $attachments = [];
        if (($msg['hasAttachments'] ?? false) === true) {
            $attachments = $this->fetchAttachments(
                $channel,
                $accessToken,
                (string) ($msg['id'] ?? ''),
                $maxAttBytes,
            );
        }

        // Headers — for threading. Graph returns
        // internetMessageHeaders as a list of {name, value} when
        // $select includes it; we re-shape into the case-sensitive
        // header-map MailThreader expects.
        $headers = ['Message-ID' => $messageId];
        foreach (($msg['internetMessageHeaders'] ?? []) as $h) {
            if (!is_array($h) || !isset($h['name'])) continue;
            $headers[(string) $h['name']] = (string) ($h['value'] ?? '');
        }
        $headers['Subject'] = $subject;
        $headers['From'] = $senderRaw;

        $event = (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($messageId)
            ->setSenderRaw($senderRaw)
            ->setSubject(mb_substr($subject, 0, 250))
            ->setBody($bodyText)
            ->setAttachments($attachments)
            ->setReceivedAt($receivedAt)
            ->setTraceUrl(isset($msg['id']) ? sprintf('https://outlook.office.com/mail/inbox/id/%s', (string) $msg['id']) : null)
            ->setSourceMetadata([
                'graphId' => (string) ($msg['id'] ?? ''),
                'bodyTruncated' => $bodyTruncated,
                'fullBodyStoredAt' => $fullBodyPath,
                'headers' => $headers,
            ]);

        if ($fromAddr !== '') {
            $contact = $this->contacts->findOneBy([
                'workspace' => $channel->getWorkspace(),
                'email' => strtolower($fromAddr),
            ]);
            if ($contact instanceof Contact) {
                $event->setSenderContact($contact);
            }
        }
        return $event;
    }

    /**
     * @param array<string, mixed> $msg
     */
    private function buildOversizedStub(Channel $channel, array $msg, string $messageId, int $size, int $limit): InboundEvent
    {
        return (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($messageId)
            ->setSubject(mb_substr((string) ($msg['subject'] ?? ''), 0, 250))
            ->setBody(sprintf(
                "[Mail skipped — %.1f MB exceeds the channel's %.1f MB limit. Headers were captured; the mail remains in the source mailbox.]",
                $size / 1024 / 1024,
                $limit / 1024 / 1024,
            ))
            ->setState(InboundEventState::Dismissed)
            ->setSourceMetadata([
                'graphId' => (string) ($msg['id'] ?? ''),
                'oversized' => true,
                'sizeBytes' => $size,
                'limitBytes' => $limit,
                'headers' => [
                    'Message-ID' => $messageId,
                    'Subject' => (string) ($msg['subject'] ?? ''),
                    'From' => $msg['from']['emailAddress']['address'] ?? null,
                ],
            ]);
    }

    private function fetchBodyText(string $accessToken, string $graphId): string
    {
        if ($graphId === '') return '';
        $url = $this->graphUrl(sprintf('/me/messages/%s?$select=body,bodyPreview', rawurlencode($graphId)));
        try {
            $resp = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]);
            $data = $resp->toArray(false);
        } catch (TransportExceptionInterface) {
            return '';
        }
        $body = $data['body'] ?? null;
        $content = is_array($body) ? (string) ($body['content'] ?? '') : '';
        $contentType = is_array($body) ? strtolower((string) ($body['contentType'] ?? 'text')) : 'text';
        if ($contentType === 'html') {
            $content = trim(strip_tags($content));
        }
        return $content !== '' ? $content : (string) ($data['bodyPreview'] ?? '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAttachments(Channel $channel, string $accessToken, string $graphId, int $maxBytes): array
    {
        if ($graphId === '') return [];
        $url = $this->graphUrl(sprintf('/me/messages/%s/attachments', rawurlencode($graphId)));
        try {
            $resp = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]);
            $data = $resp->toArray(false);
        } catch (TransportExceptionInterface) {
            return [];
        }
        $out = [];
        foreach (($data['value'] ?? []) as $att) {
            $size = (int) ($att['size'] ?? 0);
            $entry = [
                'filename' => (string) ($att['name'] ?? 'attachment.bin'),
                'mimeType' => (string) ($att['contentType'] ?? 'application/octet-stream'),
                'sizeBytes' => $size,
                'storedAt' => null,
                'oversized' => false,
            ];
            if ($size > $maxBytes || empty($att['contentBytes'])) {
                $entry['oversized'] = $size > $maxBytes;
                $out[] = $entry;
                continue;
            }
            $bytes = base64_decode((string) $att['contentBytes'], true);
            if ($bytes === false) {
                $out[] = $entry;
                continue;
            }
            $written = $this->fileStorage->writeBytes(
                $bytes,
                $channel->getWorkspace(),
                Uuid::v7(),
                Uuid::v7(),
                $entry['filename'],
            );
            $entry['storedAt'] = $written['path'];
            $entry['checksum'] = $written['checksum'];
            unset($bytes);
            $out[] = $entry;
        }
        return $out;
    }

    private function graphUrl(string $path): string
    {
        return self::GRAPH_BASE . $path;
    }

    // ---- outbound --------------------------------------------------

    public function send(Channel $channel, OutboundMessage $message): OutboundResult
    {
        $accessToken = $this->oauth->ensureAccessToken($channel);
        $outCfg = $channel->getOutboundConfig();
        $maxOutBytes = (int) ($outCfg['maxOutboundBytes'] ?? self::DEFAULT_MAX_OUTBOUND_BYTES);

        $bodyBytes = \strlen($message->getBody());
        $attBytes = 0;
        foreach ($message->getAttachments() as $att) {
            $attBytes += (int) ($att['sizeBytes'] ?? 0);
        }
        if (($bodyBytes + $attBytes) > $maxOutBytes) {
            return OutboundResult::failed(sprintf(
                'Message size %.1f MB exceeds outbound limit %.1f MB.',
                ($bodyBytes + $attBytes) / 1024 / 1024,
                $maxOutBytes / 1024 / 1024,
            ));
        }

        $payload = [
            'message' => [
                'subject' => $message->getSubject() ?? '(no subject)',
                'body' => [
                    'contentType' => 'Text',
                    'content' => $message->getBody(),
                ],
                'toRecipients' => [[
                    'emailAddress' => ['address' => $this->extractEmail($message->getRecipientRaw())],
                ]],
            ],
            'saveToSentItems' => true,
        ];

        // Thread-stitching: add In-Reply-To + References as
        // internetMessageHeaders. Graph silently ignores reserved
        // header names like Message-ID so the request stays well-formed.
        $inReplyTo = $message->getInReplyToInboundEvent();
        if ($inReplyTo !== null) {
            $payload['message']['internetMessageHeaders'] = [
                ['name' => 'In-Reply-To', 'value' => '<' . $inReplyTo->getExternalId() . '>'],
                ['name' => 'References', 'value' => $this->referencesChain($inReplyTo)],
            ];
        }

        // Attachments
        $atts = [];
        foreach ($message->getAttachments() as $att) {
            $path = $att['storedAt'] ?? null;
            if (!is_string($path) || $path === '') continue;
            $stream = $this->fileStorage->readStreamByPath($path);
            if ($stream === null) continue;
            $bytes = stream_get_contents($stream);
            if (\is_resource($stream)) fclose($stream);
            if ($bytes === false) continue;
            $atts[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => (string) ($att['filename'] ?? 'attachment.bin'),
                'contentType' => (string) ($att['mimeType'] ?? 'application/octet-stream'),
                'contentBytes' => base64_encode($bytes),
            ];
        }
        if ($atts !== []) {
            $payload['message']['attachments'] = $atts;
        }

        try {
            $response = $this->httpClient->request('POST', $this->graphUrl('/me/sendMail'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
            ]);
            $status = $response->getStatusCode();
            if ($status === 202 || $status === 200) {
                // Graph doesn't return a Message-ID from sendMail (the
                // message is queued, ID is assigned async); leave empty
                // and let the next inbound delta surface it.
                return OutboundResult::sent('');
            }
            $body = $response->getContent(false);
            return OutboundResult::failed("Graph sendMail returned $status: " . substr($body, 0, 200));
        } catch (\Throwable $e) {
            return OutboundResult::failed('Graph sendMail failed: ' . $e->getMessage());
        }
    }

    private function extractEmail(string $raw): string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
            return $m[1];
        }
        return trim($raw);
    }

    private function referencesChain(InboundEvent $inReplyTo): string
    {
        $existing = (string) ($inReplyTo->getSourceMetadata()['headers']['References'] ?? '');
        $own = '<' . $inReplyTo->getExternalId() . '>';
        return trim($existing === '' ? $own : $existing . ' ' . $own);
    }

    public function selfTest(Channel $channel): TestResult
    {
        try {
            $accessToken = $this->oauth->ensureAccessToken($channel);
        } catch (\Throwable $e) {
            return TestResult::failed('OAuth token unavailable: ' . $e->getMessage());
        }
        try {
            $response = $this->httpClient->request('GET', $this->graphUrl('/me'), [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
                'timeout' => 8,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            return TestResult::failed('Graph /me unreachable: ' . $e->getMessage());
        }
        if ($status >= 400) {
            return TestResult::failed(sprintf('Graph /me returned %d: %s', $status, (string) ($body['error']['message'] ?? '')));
        }
        $upn = (string) ($body['userPrincipalName'] ?? $body['mail'] ?? 'unknown');
        return TestResult::ok(sprintf('Verbunden als %s.', $upn), ['userPrincipalName' => $upn]);
    }
}
