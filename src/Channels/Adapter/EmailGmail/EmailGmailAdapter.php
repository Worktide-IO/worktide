<?php

declare(strict_types=1);

namespace App\Channels\Adapter\EmailGmail;

use App\Channels\Adapter\Email\MailThreader;
use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\OAuth\OAuth2Client;
use App\Channels\OAuth\OAuth2TokenException;
use App\Channels\OutboundAdapter;
use App\Channels\OutboundResult;
use App\Channels\Testable;
use App\Channels\TestResult;
use App\Channels\WebhookNotSupportedException;
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
 * Google Workspace / Gmail channel via the Gmail REST API.
 *
 * Inbound: incremental pull via Gmail historyId. First run uses
 * `users/me/messages?q=newer_than:30d&maxResults=50` to bootstrap a
 * cursor; subsequent runs use `users/me/history?startHistoryId=...`
 * which yields just the IDs of messages added since.
 *
 * Outbound: `users/me/messages/send` with a base64url-encoded RFC
 * 5322 mime message. Threading is via the standard In-Reply-To +
 * References headers plus an optional `threadId` that we set when
 * the InboundEvent we're replying to has Gmail's thread ID in its
 * source metadata.
 *
 * Same large-mail hardening shape as EmailImapAdapter / EmailGraphAdapter:
 * pre-fetch size, refuse big mails, truncate inline body, attachments
 * via FileStorage.
 */
final class EmailGmailAdapter implements InboundAdapter, OutboundAdapter, Testable
{
    public const CODE = 'email_gmail';
    private const GMAIL_BASE = 'https://gmail.googleapis.com/gmail/v1';

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
    public function getLabel(): string { return 'E-Mail (Gmail / Google Workspace)'; }

    // ---- inbound ---------------------------------------------------

    public function pull(Channel $channel): InboundResult
    {
        $accessToken = $this->oauth->ensureAccessToken($channel);
        $cfg = $channel->getInboundConfig();
        $maxBytes = (int) ($cfg['maxMessageBytes'] ?? self::DEFAULT_MAX_MESSAGE_BYTES);
        $batchLimit = (int) ($cfg['pullBatchLimit'] ?? self::DEFAULT_PULL_BATCH_LIMIT);
        $labelIds = is_array($cfg['labelIds'] ?? null) ? $cfg['labelIds'] : ['INBOX'];

        $cursor = $cfg['cursor'] ?? null;
        $messageIds = is_string($cursor) && $cursor !== ''
            ? $this->fetchHistoryIds($accessToken, $cursor, $batchLimit)
            : $this->fetchInitialIds($accessToken, $labelIds, $batchLimit);

        $newEvents = [];
        $newCursor = $cursor;

        foreach ($messageIds as $gmailId) {
            $msg = $this->fetchMessageMeta($accessToken, $gmailId);
            if ($msg === null) continue;
            $messageId = $this->extractMessageId($msg, $gmailId);
            if ($this->events->findByExternalId($channel, $messageId) !== null) {
                continue;
            }
            $size = (int) ($msg['sizeEstimate'] ?? 0);
            if ($size > $maxBytes) {
                $event = $this->buildOversizedStub($channel, $msg, $messageId, $gmailId, $size, $maxBytes);
                $this->em->persist($event);
                $this->threader->attach($channel, $event);
                $newEvents[] = $event;
                continue;
            }
            $event = $this->buildEvent($channel, $msg, $messageId, $gmailId, $accessToken);
            $this->em->persist($event);
            $this->threader->attach($channel, $event);
            $newEvents[] = $event;
        }

        // Snapshot the mailbox's current historyId for the next pull.
        // The Gmail API returns it on /users/me/profile or on the
        // last fetched message; either works as a starting point.
        $newHistoryId = $this->fetchHistoryId($accessToken);
        if ($newHistoryId !== null) {
            $newCursor = $newHistoryId;
        }

        return new InboundResult($newEvents, is_string($newCursor) ? $newCursor : null);
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        // Gmail push needs Cloud Pub/Sub + `users.watch` and is a
        // separate sub-phase. The polling path here is sufficient
        // for the C.5 happy path.
        throw new WebhookNotSupportedException(
            'email_gmail watch+Pub/Sub push arrives in a follow-up sub-phase.'
        );
    }

    /**
     * @return list<string>
     */
    private function fetchInitialIds(string $accessToken, array $labelIds, int $limit): array
    {
        $params = [
            'maxResults' => $limit,
            'q' => 'newer_than:30d',
        ];
        $url = $this->gmailUrl('/users/me/messages') . '?' . http_build_query($params);
        foreach ($labelIds as $l) {
            $url .= '&labelIds=' . rawurlencode((string) $l);
        }
        $data = $this->getJson($accessToken, $url);
        $ids = [];
        foreach (($data['messages'] ?? []) as $m) {
            if (isset($m['id'])) $ids[] = (string) $m['id'];
        }
        return $ids;
    }

    /**
     * @return list<string>
     */
    private function fetchHistoryIds(string $accessToken, string $startHistoryId, int $limit): array
    {
        $url = $this->gmailUrl('/users/me/history') . '?' . http_build_query([
            'startHistoryId' => $startHistoryId,
            'maxResults' => $limit,
            'historyTypes' => 'messageAdded',
        ]);
        $data = $this->getJson($accessToken, $url);
        $ids = [];
        foreach (($data['history'] ?? []) as $h) {
            foreach (($h['messagesAdded'] ?? []) as $m) {
                if (isset($m['message']['id'])) {
                    $ids[] = (string) $m['message']['id'];
                }
            }
        }
        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchMessageMeta(string $accessToken, string $gmailId): ?array
    {
        // 'full' format includes headers + body parts (we still
        // download attachment bytes separately so the response
        // size stays bounded).
        $url = $this->gmailUrl(sprintf('/users/me/messages/%s', rawurlencode($gmailId)))
            . '?format=full';
        $data = $this->getJson($accessToken, $url);
        return $data ?: null;
    }

    private function fetchHistoryId(string $accessToken): ?string
    {
        $data = $this->getJson($accessToken, $this->gmailUrl('/users/me/profile'));
        return isset($data['historyId']) ? (string) $data['historyId'] : null;
    }

    /**
     * @param array<string, mixed> $msg
     */
    private function extractMessageId(array $msg, string $gmailId): string
    {
        $headers = $this->headerMap($msg['payload']['headers'] ?? []);
        $messageId = trim((string) ($headers['Message-Id'] ?? $headers['Message-ID'] ?? ''));
        if ($messageId !== '') {
            return trim($messageId, '<>');
        }
        return 'gmail-' . $gmailId . '@gmail.local';
    }

    /**
     * @param array<string, mixed> $msg
     */
    private function buildEvent(Channel $channel, array $msg, string $messageId, string $gmailId, string $accessToken): InboundEvent
    {
        $cfg = $channel->getInboundConfig();
        $maxInline = (int) ($cfg['maxInlineBodyBytes'] ?? self::DEFAULT_MAX_INLINE_BODY_BYTES);
        $maxAttBytes = (int) ($cfg['maxAttachmentBytes'] ?? self::DEFAULT_MAX_ATTACHMENT_BYTES);

        $payload = $msg['payload'] ?? [];
        $headers = $this->headerMap($payload['headers'] ?? []);
        $subject = (string) ($headers['Subject'] ?? '');
        $fromRaw = (string) ($headers['From'] ?? '');
        $fromAddr = $this->extractEmail($fromRaw);

        $receivedAt = isset($msg['internalDate'])
            ? new \DateTimeImmutable('@' . (int) round(((int) $msg['internalDate']) / 1000))
            : new \DateTimeImmutable();

        // Walk parts to find text/plain (fallback: text/html stripped).
        [$bodyText, $attachmentParts] = $this->extractBodyAndAttachments($payload);

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

        // Attachments: each part has an attachmentId we re-fetch
        // separately to get the base64url-encoded bytes.
        $attachments = [];
        foreach ($attachmentParts as $part) {
            $size = (int) ($part['body']['size'] ?? 0);
            $entry = [
                'filename' => (string) ($part['filename'] ?? 'attachment.bin'),
                'mimeType' => (string) ($part['mimeType'] ?? 'application/octet-stream'),
                'sizeBytes' => $size,
                'storedAt' => null,
                'oversized' => false,
            ];
            $attachmentId = $part['body']['attachmentId'] ?? null;
            if ($size > $maxAttBytes || !is_string($attachmentId)) {
                $entry['oversized'] = $size > $maxAttBytes;
                $attachments[] = $entry;
                continue;
            }
            $bytes = $this->fetchAttachmentBytes($accessToken, $gmailId, $attachmentId);
            if ($bytes === null) {
                $attachments[] = $entry;
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
            $attachments[] = $entry;
        }

        $event = (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($messageId)
            ->setSenderRaw($fromRaw !== '' ? $fromRaw : null)
            ->setSubject(mb_substr($subject, 0, 250))
            ->setBody($bodyText)
            ->setAttachments($attachments)
            ->setReceivedAt($receivedAt)
            ->setTraceUrl(sprintf('https://mail.google.com/mail/u/0/#inbox/%s', $gmailId))
            ->setSourceMetadata([
                'gmailId' => $gmailId,
                'gmailThreadId' => (string) ($msg['threadId'] ?? ''),
                'bodyTruncated' => $bodyTruncated,
                'fullBodyStoredAt' => $fullBodyPath,
                'headers' => $headers + ['Message-ID' => $messageId, 'Subject' => $subject, 'From' => $fromRaw],
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
    private function buildOversizedStub(Channel $channel, array $msg, string $messageId, string $gmailId, int $size, int $limit): InboundEvent
    {
        $headers = $this->headerMap($msg['payload']['headers'] ?? []);
        return (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($messageId)
            ->setSubject(mb_substr((string) ($headers['Subject'] ?? ''), 0, 250))
            ->setBody(sprintf(
                "[Mail skipped — %.1f MB exceeds the channel's %.1f MB limit. Headers were captured; the mail remains in the source mailbox.]",
                $size / 1024 / 1024,
                $limit / 1024 / 1024,
            ))
            ->setState(InboundEventState::Dismissed)
            ->setSourceMetadata([
                'gmailId' => $gmailId,
                'gmailThreadId' => (string) ($msg['threadId'] ?? ''),
                'oversized' => true,
                'sizeBytes' => $size,
                'limitBytes' => $limit,
                'headers' => $headers,
            ]);
    }

    /**
     * Walk a Gmail MIME payload tree, return [bodyText, attachmentParts].
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function extractBodyAndAttachments(array $payload): array
    {
        $text = '';
        $html = '';
        $attachments = [];
        $stack = [$payload];
        while ($stack !== []) {
            $part = array_pop($stack);
            $mimeType = strtolower((string) ($part['mimeType'] ?? ''));
            $filename = (string) ($part['filename'] ?? '');
            if ($filename !== '') {
                $attachments[] = $part;
                continue;
            }
            if ($mimeType === 'text/plain') {
                $data = (string) ($part['body']['data'] ?? '');
                if ($data !== '') $text .= $this->base64UrlDecode($data) . "\n";
            } elseif ($mimeType === 'text/html') {
                $data = (string) ($part['body']['data'] ?? '');
                if ($data !== '') $html .= $this->base64UrlDecode($data);
            }
            foreach (($part['parts'] ?? []) as $sub) {
                $stack[] = $sub;
            }
        }
        $body = $text !== '' ? trim($text) : trim(strip_tags($html));
        return [$body, $attachments];
    }

    /**
     * @param array<int, array<string, string>> $headers
     * @return array<string, string>
     */
    private function headerMap(array $headers): array
    {
        $out = [];
        foreach ($headers as $h) {
            if (isset($h['name'], $h['value'])) {
                $out[$h['name']] = (string) $h['value'];
            }
        }
        return $out;
    }

    private function fetchAttachmentBytes(string $accessToken, string $gmailId, string $attachmentId): ?string
    {
        $url = $this->gmailUrl(sprintf(
            '/users/me/messages/%s/attachments/%s',
            rawurlencode($gmailId),
            rawurlencode($attachmentId),
        ));
        $data = $this->getJson($accessToken, $url);
        $b64 = (string) ($data['data'] ?? '');
        if ($b64 === '') return null;
        return $this->base64UrlDecode($b64);
    }

    private function base64UrlDecode(string $b64): string
    {
        return (string) base64_decode(strtr($b64, '-_', '+/'), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $accessToken, string $url): array
    {
        try {
            $resp = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]);
            return $resp->toArray(false);
        } catch (TransportExceptionInterface $e) {
            throw new OAuth2TokenException('Gmail request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function gmailUrl(string $path): string
    {
        return self::GMAIL_BASE . $path;
    }

    private function extractEmail(string $raw): string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
            return $m[1];
        }
        return trim($raw);
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

        $from = (string) ($outCfg['from'] ?? $channel->getAddress() ?? '');
        $rfc822 = $this->buildRfc822Message($message, $from);
        $base64url = rtrim(strtr(base64_encode($rfc822), '+/', '-_'), '=');

        $payload = ['raw' => $base64url];
        $gmailThreadId = $message->getInReplyToInboundEvent()?->getSourceMetadata()['gmailThreadId'] ?? null;
        if (is_string($gmailThreadId) && $gmailThreadId !== '') {
            $payload['threadId'] = $gmailThreadId;
        }

        try {
            $response = $this->httpClient->request('POST', $this->gmailUrl('/users/me/messages/send'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload, \JSON_THROW_ON_ERROR),
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() < 400) {
                return OutboundResult::sent((string) ($data['id'] ?? ''));
            }
            return OutboundResult::failed(sprintf(
                'Gmail send returned %d: %s',
                $response->getStatusCode(),
                (string) ($data['error']['message'] ?? 'unknown'),
            ));
        } catch (\Throwable $e) {
            return OutboundResult::failed('Gmail send failed: ' . $e->getMessage());
        }
    }

    private function buildRfc822Message(OutboundMessage $message, string $from): string
    {
        $boundary = 'wt-' . bin2hex(random_bytes(8));
        $lines = [];
        $lines[] = "From: $from";
        $lines[] = 'To: ' . $message->getRecipientRaw();
        $lines[] = 'Subject: ' . (string) ($message->getSubject() ?? '(no subject)');
        $lines[] = 'MIME-Version: 1.0';

        $inReplyTo = $message->getInReplyToInboundEvent();
        if ($inReplyTo !== null) {
            $lines[] = 'In-Reply-To: <' . $inReplyTo->getExternalId() . '>';
            $existing = (string) ($inReplyTo->getSourceMetadata()['headers']['References'] ?? '');
            $own = '<' . $inReplyTo->getExternalId() . '>';
            $lines[] = 'References: ' . trim($existing === '' ? $own : $existing . ' ' . $own);
        }

        $attachments = $message->getAttachments();
        if ($attachments === []) {
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            $lines[] = $message->getBody();
        } else {
            $lines[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $lines[] = '';
            $lines[] = '--' . $boundary;
            $lines[] = 'Content-Type: text/plain; charset=UTF-8';
            $lines[] = 'Content-Transfer-Encoding: 8bit';
            $lines[] = '';
            $lines[] = $message->getBody();
            foreach ($attachments as $att) {
                $path = $att['storedAt'] ?? null;
                if (!is_string($path) || $path === '') continue;
                $stream = $this->fileStorage->readStreamByPath($path);
                if ($stream === null) continue;
                $bytes = stream_get_contents($stream);
                if (\is_resource($stream)) fclose($stream);
                if ($bytes === false) continue;
                $lines[] = '--' . $boundary;
                $lines[] = 'Content-Type: ' . (string) ($att['mimeType'] ?? 'application/octet-stream') . '; name="' . ($att['filename'] ?? 'attachment.bin') . '"';
                $lines[] = 'Content-Transfer-Encoding: base64';
                $lines[] = 'Content-Disposition: attachment; filename="' . ($att['filename'] ?? 'attachment.bin') . '"';
                $lines[] = '';
                $lines[] = chunk_split(base64_encode($bytes), 76, "\r\n");
            }
            $lines[] = '--' . $boundary . '--';
        }
        return implode("\r\n", $lines);
    }

    public function selfTest(Channel $channel): TestResult
    {
        try {
            $accessToken = $this->oauth->ensureAccessToken($channel);
        } catch (\Throwable $e) {
            return TestResult::failed('OAuth token unavailable: ' . $e->getMessage());
        }
        try {
            $data = $this->getJson($accessToken, $this->gmailUrl('/users/me/profile'));
        } catch (\Throwable $e) {
            return TestResult::failed('Gmail /profile unreachable: ' . $e->getMessage());
        }
        $email = (string) ($data['emailAddress'] ?? '');
        if ($email === '') {
            return TestResult::warning('Gmail profile responded but no emailAddress field — scope incomplete?');
        }
        return TestResult::ok(sprintf('Verbunden als %s.', $email), ['emailAddress' => $email]);
    }
}
