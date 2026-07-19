<?php

declare(strict_types=1);

namespace App\Channels\Adapter\EmailWebhook;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\PullNotSupportedException;
use App\Entity\Channel;
use App\Entity\InboundEvent;
use App\Repository\ContactRepository;
use App\Repository\InboundEventRepository;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;
use Webklex\PHPIMAP\Message;

/**
 * Provider-agnostic inbound-mail webhook receiver.
 *
 * Rather than one adapter per SaaS (SendGrid/Mailgun/Resend, each with its own
 * signature scheme), this accepts inbound mail from ANY source that can POST to
 * the per-channel webhook URL — a self-hosted Postal server, a `sendmail | curl`
 * pipe, or a provider's inbound-parse hook. It reads the two shapes that cover
 * essentially everything:
 *
 *   1. **Raw RFC822 MIME** — as the request body (Content-Type message/rfc822),
 *      or in a JSON `message`/`raw`/`rfc822`/`email` field (base64 when
 *      `base64: true`, which is exactly how Postal's HTTP endpoint posts).
 *   2. **Generic JSON / form fields** — {from,to,subject,text,html,messageId,
 *      inReplyTo,references,date} for simple integrations.
 *
 * Auth: the URL token is the credential (see WebhookIngestController). If the
 * channel additionally configures `authConfig.signingSecret`, an HMAC-SHA256 of
 * the raw body must match the signature header (`authConfig.signatureHeader`,
 * default `X-Signature`, value optionally `sha256=`-prefixed) — defence in depth
 * for providers that sign.
 *
 * Push-only: {@see pull()} throws. Threading reuses the MailThreader (this
 * adapterCode is mapped to it in services.yaml), so the sourceMetadata.headers
 * shape mirrors the IMAP adapter exactly.
 */
final class GenericInboundMailAdapter implements InboundAdapter
{
    public const CODE = 'email_webhook';

    private const DEFAULT_MAX_INLINE_BODY_BYTES = 65536;
    private const DEFAULT_MAX_ATTACHMENT_BYTES = 26214400; // 25 MiB

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundEventRepository $events,
        private readonly ContactRepository $contacts,
        private readonly FileStorage $fileStorage,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'E-Mail (Webhook)';
    }

    public function pull(Channel $channel): InboundResult
    {
        throw new PullNotSupportedException('email_webhook is push-only.');
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        $raw = $request->getContent();
        $this->verifySignature($channel, $request, $raw);

        $mime = $this->extractRawMime($request, $raw);
        $event = $mime !== null
            ? $this->eventFromMime($channel, $mime)
            : $this->eventFromFields($channel, $this->extractFields($request, $raw));

        if ($event === null) {
            // Dedup hit (provider retry) — a successful no-op.
            return InboundResult::empty();
        }

        $this->em->persist($event);

        return new InboundResult([$event]);
    }

    // ---- signature -------------------------------------------------

    private function verifySignature(Channel $channel, Request $request, string $raw): void
    {
        $secret = (string) ($channel->getAuthConfig()['signingSecret'] ?? '');
        if ($secret === '') {
            return; // token-only auth
        }
        $headerName = (string) ($channel->getAuthConfig()['signatureHeader'] ?? 'X-Signature');
        $provided = (string) $request->headers->get($headerName, '');
        $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        $expected = hash_hmac('sha256', $raw, $secret);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new AccessDeniedHttpException('Invalid webhook signature.');
        }
    }

    // ---- shape detection -------------------------------------------

    private function extractRawMime(Request $request, string $raw): ?string
    {
        $json = $this->decodeJson($raw);
        if ($json !== null) {
            foreach (['message', 'raw', 'rfc822', 'mime', 'email'] as $key) {
                $val = $json[$key] ?? null;
                if (\is_string($val) && $val !== '' && $this->looksLikeMime($this->maybeBase64($val, $json))) {
                    return $this->maybeBase64($val, $json);
                }
            }

            return null; // JSON but no MIME field → generic-fields path
        }

        $contentType = (string) $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'message/rfc822') || $this->looksLikeMime($raw)) {
            return $raw;
        }

        return null;
    }

    /** @param array<string, mixed> $json */
    private function maybeBase64(string $val, array $json): string
    {
        $isB64 = ($json['base64'] ?? false) === true
            || ($json['base64'] ?? '') === 'true'
            || (!$this->looksLikeMime($val) && base64_decode($val, true) !== false && $this->looksLikeMime((string) base64_decode($val, true)));

        return $isB64 ? (string) base64_decode($val, true) : $val;
    }

    private function looksLikeMime(string $s): bool
    {
        // A header block (Name: value lines) followed by a blank line — the
        // separator is CRLFCRLF in real mail, LFLF from some sources.
        return preg_match('/^[A-Za-z][A-Za-z0-9-]*:\s/m', $s) === 1
            && (str_contains($s, "\r\n\r\n") || str_contains($s, "\n\n"));
    }

    private function decodeJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || ($raw[0] !== '{' && $raw[0] !== '[')) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed> */
    private function extractFields(Request $request, string $raw): array
    {
        $json = $this->decodeJson($raw);
        if ($json !== null) {
            // Postal-style: fields may sit under a "payload"/"message" envelope.
            $inner = $json['payload'] ?? $json['message'] ?? null;

            return \is_array($inner) ? $inner : $json;
        }

        // multipart/form-data or x-www-form-urlencoded (SendGrid/Mailgun style).
        return $request->request->all();
    }

    // ---- event builders --------------------------------------------

    private function eventFromMime(Channel $channel, string $mime): ?InboundEvent
    {
        $msg = Message::fromString($mime);

        $messageId = $this->normaliseMessageId((string) $msg->getMessageId());
        if ($messageId === '') {
            $messageId = 'sha256:' . hash('sha256', $mime);
        }
        if ($this->events->findByExternalId($channel, $messageId) !== null) {
            return null;
        }

        $senderRaw = trim((string) $msg->getFrom()) ?: null;
        $fromAddr = $msg->getFrom()->first()?->mail;

        $text = (string) $msg->getTextBody();
        if ($text === '') {
            $html = (string) $msg->getHTMLBody();
            $text = $html !== '' ? trim(strip_tags($html)) : '';
        }

        $receivedAt = new \DateTimeImmutable();
        try {
            $date = (string) $msg->getDate();
            if ($date !== '') {
                $receivedAt = new \DateTimeImmutable($date);
            }
        } catch (\Exception) {
            // keep "now"
        }

        $references = trim((string) $msg->getReferences());

        return $this->buildEvent($channel, [
            'externalId' => $messageId,
            'senderRaw' => $senderRaw,
            'fromAddr' => $fromAddr,
            'subject' => (string) $msg->getSubject(),
            'body' => $text,
            'receivedAt' => $receivedAt,
            'headers' => [
                'Message-ID' => $messageId,
                'In-Reply-To' => $this->normaliseMessageId((string) $msg->getInReplyTo()) ?: null,
                'References' => $references !== '' ? $references : null,
                'Subject' => (string) $msg->getSubject(),
                'From' => $senderRaw,
                'List-Unsubscribe' => $this->headerValue($msg, 'list_unsubscribe'),
                'Precedence' => $this->headerValue($msg, 'precedence'),
                'Auto-Submitted' => $this->headerValue($msg, 'auto_submitted'),
            ],
            'attachments' => $this->extractAttachments($channel, $msg),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function eventFromFields(Channel $channel, array $fields): ?InboundEvent
    {
        $from = $this->firstString($fields, ['from', 'sender', 'From', 'mail_from']);
        $subject = $this->firstString($fields, ['subject', 'Subject']);
        $text = $this->firstString($fields, ['text', 'body-plain', 'plain', 'body']);
        $html = $this->firstString($fields, ['html', 'body-html']);
        if ($from === null && $subject === null && $text === null && $html === null) {
            throw new BadRequestHttpException('Unrecognised inbound payload: neither raw MIME nor known mail fields.');
        }

        $body = $text ?? ($html !== null ? trim(strip_tags($html)) : '');
        $messageId = $this->normaliseMessageId($this->firstString($fields, ['messageId', 'message-id', 'Message-Id']) ?? '');
        if ($messageId === '') {
            $messageId = 'sha256:' . hash('sha256', ($from ?? '') . '|' . ($subject ?? '') . '|' . $body);
        }
        if ($this->events->findByExternalId($channel, $messageId) !== null) {
            return null;
        }

        $receivedAt = new \DateTimeImmutable();
        $date = $this->firstString($fields, ['date', 'Date']);
        if ($date !== null) {
            try {
                $receivedAt = new \DateTimeImmutable($date);
            } catch (\Exception) {
                // keep "now"
            }
        }

        $fromAddr = $from !== null && preg_match('/<([^>]+)>/', $from, $m) === 1
            ? trim($m[1])
            : ($from !== null && str_contains($from, '@') ? trim($from) : null);

        return $this->buildEvent($channel, [
            'externalId' => $messageId,
            'senderRaw' => $from,
            'fromAddr' => $fromAddr,
            'subject' => (string) ($subject ?? ''),
            'body' => $body,
            'receivedAt' => $receivedAt,
            'headers' => [
                'Message-ID' => $messageId,
                'In-Reply-To' => $this->normaliseMessageId($this->firstString($fields, ['inReplyTo', 'in-reply-to']) ?? '') ?: null,
                'References' => $this->firstString($fields, ['references', 'References']),
                'Subject' => $subject,
                'From' => $from,
            ],
            'attachments' => [], // generic-field payloads don't carry inline attachments
        ]);
    }

    /**
     * @param array{externalId:string,senderRaw:?string,fromAddr:?string,subject:string,body:string,receivedAt:\DateTimeImmutable,headers:array<string,mixed>,attachments:list<array<string,mixed>>} $parts
     */
    private function buildEvent(Channel $channel, array $parts): InboundEvent
    {
        $body = $parts['body'];
        $cfg = $channel->getInboundConfig();
        $maxInline = (int) ($cfg['maxInlineBodyBytes'] ?? self::DEFAULT_MAX_INLINE_BODY_BYTES);
        $bodyTruncated = false;
        $fullBodyPath = null;
        if (\strlen($body) > $maxInline) {
            $written = $this->fileStorage->writeBytes($body, $channel->getWorkspace(), Uuid::v7(), Uuid::v7(), 'mail-body.txt');
            $fullBodyPath = $written['path'];
            $body = mb_strcut($body, 0, $maxInline) . "\n\n[... body truncated; full body stored as file ...]";
            $bodyTruncated = true;
        }

        $event = (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($parts['externalId'])
            ->setSenderRaw($parts['senderRaw'] !== null ? mb_substr($parts['senderRaw'], 0, 200) : null)
            ->setSubject(mb_substr($parts['subject'], 0, 250))
            ->setBody($body)
            ->setAttachments($parts['attachments'])
            ->setReceivedAt($parts['receivedAt'])
            ->setSourceMetadata([
                'bodyTruncated' => $bodyTruncated,
                'fullBodyStoredAt' => $fullBodyPath,
                'headers' => array_filter($parts['headers'], static fn ($v) => $v !== null),
            ]);

        // Sender → Contact lookup (best-effort).
        if ($parts['fromAddr'] !== null && str_contains($parts['fromAddr'], '@')) {
            $contact = $this->contacts->findOneByWorkspaceAndEmail($channel->getWorkspace(), $parts['fromAddr']);
            if ($contact !== null) {
                $event->setSenderContact($contact);
            }
        }

        return $event;
    }

    /** @return list<array<string, mixed>> */
    private function extractAttachments(Channel $channel, Message $msg): array
    {
        $cfg = $channel->getInboundConfig();
        $maxBytes = (int) ($cfg['maxAttachmentBytes'] ?? self::DEFAULT_MAX_ATTACHMENT_BYTES);
        $out = [];
        foreach ($msg->getAttachments() as $att) {
            $size = (int) $att->getSize();
            $entry = [
                'filename' => (string) $att->getName(),
                'mimeType' => (string) $att->getContentType(),
                'sizeBytes' => $size,
                'storedAt' => null,
                'oversized' => $size > $maxBytes,
            ];
            if (!$entry['oversized']) {
                $written = $this->fileStorage->writeBytes(
                    (string) $att->getContent(),
                    $channel->getWorkspace(),
                    Uuid::v7(),
                    Uuid::v7(),
                    $entry['filename'] ?: 'attachment.bin',
                );
                $entry['storedAt'] = $written['path'];
                $entry['checksum'] = $written['checksum'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    // ---- helpers ---------------------------------------------------

    private function normaliseMessageId(string $id): string
    {
        return trim($id, " \t<>");
    }

    private function headerValue(Message $msg, string $attr): ?string
    {
        try {
            $v = trim((string) $msg->get($attr));

            return $v !== '' ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $keys
     */
    private function firstString(array $fields, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($fields[$k]) && \is_string($fields[$k]) && trim($fields[$k]) !== '') {
                return trim($fields[$k]);
            }
        }

        return null;
    }
}
