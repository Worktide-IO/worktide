<?php

declare(strict_types=1);

namespace App\Channels\Adapter\Email;

use App\Channels\ConversationThreader;
use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\OutboundAdapter;
use App\Channels\OutboundResult;
use App\Channels\Testable;
use App\Channels\TestResult;
use App\Entity\Channel;
use App\Entity\Contact;
use App\Entity\InboundEvent;
use App\Entity\OutboundMessage;
use App\Entity\Enum\InboundEventState;
use App\Repository\ContactRepository;
use App\Repository\InboundEventRepository;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Classic IMAP-pull + SMTP-send adapter — the simplest mail channel
 * shape, no OAuth, plain user/password against a server the operator
 * spelled out themselves.
 *
 * Inbound (IMAP):
 *   - Pulls every UID strictly greater than the cursor stored on
 *     Channel.inboundConfig['cursor'] (default 0).
 *   - For every fetched message we build an InboundEvent keyed by
 *     Message-ID (UNIQUE(channel, externalId) makes the pull
 *     idempotent — re-running on the same mailbox is a no-op).
 *   - Sender resolution: a Contact lookup on the From-header
 *     primary-address. Conversation threading is delegated to
 *     {@see MailThreader} via the registry.
 *
 * Outbound (SMTP):
 *   - Builds a fresh `symfony/mailer` Transport per channel from
 *     Channel.outboundConfig + Channel.authConfig so each Channel
 *     uses its own credentials (the global MAILER_DSN is irrelevant).
 *   - Sets In-Reply-To + References when the message answers a
 *     known InboundEvent — keeps the thread intact on the
 *     recipient's side.
 *
 * Channel.inboundConfig shape:
 *   { host, port, encryption: 'ssl'|'tls'|'', folder, cursor? }
 *
 * Channel.outboundConfig shape:
 *   { host, port, encryption: 'tls'|'ssl'|'', from, fromName? }
 *
 * Channel.authConfig shape (auto-decrypted by the cipher listener):
 *   { username, password }                  // can re-use the same for SMTP if not split
 *   { username, password, smtpUsername?, smtpPassword? }
 */
final class EmailImapAdapter implements InboundAdapter, OutboundAdapter, Testable
{
    public const CODE = 'email_imap';

    /** Refuse to fetch the body of a message bigger than this. Provider-tunable. */
    private const DEFAULT_MAX_MESSAGE_BYTES = 25 * 1024 * 1024;

    /** Truncate inline body in the DB column; the full body is offloaded to FileStorage. */
    private const DEFAULT_MAX_INLINE_BODY_BYTES = 256 * 1024;

    /** Per-attachment cap — bigger ones get recorded as metadata-only stubs. */
    private const DEFAULT_MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    /** Cap for the SMTP message size BEFORE handing to the transport. */
    private const DEFAULT_MAX_OUTBOUND_BYTES = 25 * 1024 * 1024;

    /** Cap how many messages we touch in one pull cycle (memory ceiling). */
    private const DEFAULT_PULL_BATCH_LIMIT = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundEventRepository $events,
        private readonly ContactRepository $contacts,
        private readonly MailThreader $threader,
        private readonly FileStorage $fileStorage,
    ) {}

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'E-Mail (IMAP/SMTP)';
    }

    // ---- inbound ---------------------------------------------------

    public function pull(Channel $channel): InboundResult
    {
        $client = $this->makeClient($channel);
        $client->connect();
        try {
            $cfg = $channel->getInboundConfig();
            $folderName = (string) ($cfg['folder'] ?? 'INBOX');
            $cursor = (int) ($cfg['cursor'] ?? 0);
            $maxBytes = (int) ($cfg['maxMessageBytes'] ?? self::DEFAULT_MAX_MESSAGE_BYTES);
            $batchLimit = (int) ($cfg['pullBatchLimit'] ?? self::DEFAULT_PULL_BATCH_LIMIT);

            $folder = $client->getFolder($folderName);
            if ($folder === null) {
                return InboundResult::empty();
            }

            // Lightweight first pass: fetch headers + sizes only.
            // RFC822.SIZE comes back without downloading the body so
            // we can refuse oversized messages BEFORE they hit memory.
            $query = $folder->messages()->setFetchBody(false);
            if ($cursor > 0) {
                // Server-side UID-range fetch (everything newer than the cursor).
                // php-imap 6.x dropped setFetchUid(); whereUid() emits the same
                // `UID N:*` search criterion and stays chainable with limit().
                $query->whereUid((string) ($cursor + 1) . ':*');
            } else {
                // First run. With a backfill window we only pull messages since
                // that date (so a 10GB mailbox's full history isn't ingested);
                // later runs continue forward via the UID cursor, which never
                // revisits the older (out-of-window) UIDs. Without a window we
                // fall back to the whole folder.
                $backfillSince = isset($cfg['backfillSince']) && \is_string($cfg['backfillSince']) && $cfg['backfillSince'] !== ''
                    ? $cfg['backfillSince']
                    : null;
                if ($backfillSince !== null) {
                    $query->since(new \DateTimeImmutable($backfillSince));
                } else {
                    $query->all();
                }
            }
            $headers = $query->limit($batchLimit)->get();

            $newEvents = [];
            $newCursor = $cursor;

            foreach ($headers as $msgStub) {
                $uid = (int) $msgStub->getUid();
                if ($uid <= $cursor) {
                    continue;
                }
                $newCursor = max($newCursor, $uid);

                $messageId = $this->extractMessageId($msgStub);
                if ($this->events->findByExternalId($channel, $messageId) !== null) {
                    continue;
                }

                $size = (int) $msgStub->getSize();
                if ($size > $maxBytes) {
                    $event = $this->buildOversizedStub($channel, $msgStub, $messageId, $size, $maxBytes);
                    $this->em->persist($event);
                    $this->threader->attach($channel, $event);
                    $newEvents[] = $event;
                    continue;
                }

                // Under the cap → second IMAP fetch with full body for
                // this single UID. Done one-by-one so a single oversize
                // message can't sneak through if size metadata lied.
                $full = $folder->messages()->getMessageByUid($uid);
                if ($full === null) {
                    continue;
                }

                $event = $this->buildEvent($channel, $full, $messageId);
                $this->em->persist($event);
                $this->threader->attach($channel, $event);
                $newEvents[] = $event;

                // Hint GC to drop the heavy message object — webklex
                // holds the raw mime in memory until garbage-collected.
                unset($full);
            }

            return new InboundResult($newEvents, (string) $newCursor);
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Build a dismissed-state InboundEvent for a message that exceeds
     * the configured size cap. Keeps the header for audit but never
     * pulls the body — protects the worker from OOM and the DB from
     * multi-MB rows that nobody can render anyway.
     */
    private function buildOversizedStub(
        Channel $channel,
        Message $msg,
        string $messageId,
        int $actualBytes,
        int $maxBytes,
    ): InboundEvent {
        $header = $msg->getHeader();
        $subject = $this->headerString($header?->get('subject')) ?? '';
        return (new InboundEvent())
            ->setWorkspace($channel->getWorkspace())
            ->setChannel($channel)
            ->setExternalId($messageId)
            ->setSubject(mb_substr($subject, 0, 250))
            ->setBody(sprintf(
                "[Mail skipped — %.1f MB exceeds the channel's %.1f MB limit. Headers were captured; full message remains on the IMAP server.]",
                $actualBytes / 1024 / 1024,
                $maxBytes / 1024 / 1024,
            ))
            ->setState(InboundEventState::Dismissed)
            ->setSourceMetadata([
                'uid' => (int) $msg->getUid(),
                'folder' => (string) ($channel->getInboundConfig()['folder'] ?? 'INBOX'),
                'oversized' => true,
                'sizeBytes' => $actualBytes,
                'limitBytes' => $maxBytes,
                'headers' => [
                    'Message-ID' => $messageId,
                    'Subject' => $subject,
                    'From' => $this->headerString($header?->get('from')),
                    'Date' => $this->headerString($header?->get('date')),
                ],
            ]);
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        // IMAP is poll-only. Push subscriptions on plain IMAP would
        // need IDLE which we don't run from a request thread; the
        // EmailGraphAdapter (Phase C.5) is the push-capable path.
        throw new \App\Channels\WebhookNotSupportedException(
            'email_imap is poll-only; use email_graph or email_gmail for push subscriptions.'
        );
    }

    private function buildEvent(Channel $channel, Message $msg, string $messageId): InboundEvent
    {
        $header = $msg->getHeader();
        // webklex normalises header names to snake_case; .get() returns
        // an Attribute object whose ->toString() / scalar coercion gives
        // us the raw string.
        $references = $this->headerArray($header?->get('references'));
        $inReplyTo = $this->headerString($header?->get('in_reply_to'));
        $subject = $this->headerString($header?->get('subject')) ?? '';

        $fromAddr = null;
        $fromName = null;
        $fromAttr = $header?->get('from');
        if (is_array($fromAttr) && isset($fromAttr[0])) {
            $fromAddr = $fromAttr[0]->mail ?? null;
            $fromName = $fromAttr[0]->personal ?? null;
        } elseif (is_object($fromAttr)) {
            // sometimes webklex hands back a single object
            $fromAddr = $fromAttr->mail ?? ($fromAttr->get('mail') ?? null);
            $fromName = $fromAttr->personal ?? ($fromAttr->get('personal') ?? null);
        }
        $senderRaw = $fromName ? sprintf('%s <%s>', $fromName, $fromAddr) : ($fromAddr ?? null);

        $receivedAt = new \DateTimeImmutable();
        $dateAttr = $header?->get('date');
        if (is_object($dateAttr)) {
            try {
                $receivedAt = new \DateTimeImmutable((string) $dateAttr);
            } catch (\Exception) {
                // keep "now" fallback
            }
        }

        // Body: prefer text/plain, fall back to a tag-stripped HTML body.
        $text = $msg->getTextBody();
        if ($text === '' || $text === null) {
            $html = $msg->getHTMLBody();
            if ($html !== '' && $html !== null) {
                $text = trim(strip_tags($html));
            }
        }

        // Truncate the inline body if it would otherwise bloat the DB.
        // The full body is offloaded to FileStorage; the truncated
        // version is what shows in the inbox list, the full version is
        // streamed on demand via the SPA's "load full body" button.
        $cfg = $channel->getInboundConfig();
        $maxInline = (int) ($cfg['maxInlineBodyBytes'] ?? self::DEFAULT_MAX_INLINE_BODY_BYTES);
        $bodyTruncated = false;
        $fullBodyPath = null;
        if ($text !== null && \strlen($text) > $maxInline) {
            $writePath = $this->fileStorage->writeBytes(
                $text,
                $channel->getWorkspace(),
                Uuid::v7(),
                Uuid::v7(),
                'mail-body.txt',
            );
            $fullBodyPath = $writePath['path'];
            // mb_strcut respects multi-byte boundaries so we don't
            // chop a UTF-8 character mid-sequence.
            $text = mb_strcut($text, 0, $maxInline) . "\n\n[... body truncated; full body stored as file ...]";
            $bodyTruncated = true;
        }

        // Attachments — ALWAYS to FileStorage, never inline. Even tiny
        // attachments go through storage so the DB row stays a thin
        // pointer regardless of content size.
        $maxAttachmentBytes = (int) ($cfg['maxAttachmentBytes'] ?? self::DEFAULT_MAX_ATTACHMENT_BYTES);
        $attachments = [];
        foreach ($msg->getAttachments() as $att) {
            $attSize = (int) $att->getSize();
            $entry = [
                'filename' => (string) $att->getName(),
                'mimeType' => (string) $att->getContentType(),
                'sizeBytes' => $attSize,
                'storedAt' => null,
                'oversized' => false,
            ];
            if ($attSize > $maxAttachmentBytes) {
                $entry['oversized'] = true;
                $attachments[] = $entry;
                continue;
            }
            $bytes = (string) $att->getContent();
            $written = $this->fileStorage->writeBytes(
                $bytes,
                $channel->getWorkspace(),
                Uuid::v7(),
                Uuid::v7(),
                $entry['filename'] ?: 'attachment.bin',
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
            ->setSenderRaw($senderRaw)
            ->setSubject(mb_substr($subject, 0, 250))
            ->setBody($text ?? '')
            ->setAttachments($attachments)
            ->setReceivedAt($receivedAt)
            ->setSourceMetadata([
                'uid' => (int) $msg->getUid(),
                'folder' => (string) ($channel->getInboundConfig()['folder'] ?? 'INBOX'),
                'bodyTruncated' => $bodyTruncated,
                'fullBodyStoredAt' => $fullBodyPath,
                'headers' => [
                    'Message-ID' => $messageId,
                    'In-Reply-To' => $inReplyTo,
                    'References' => $references === [] ? null : implode(' ', array_map(fn ($r) => "<$r>", $references)),
                    'Subject' => $subject,
                    'From' => $senderRaw,
                    // Newsletter/automated-mail signals for MailRelevanceClassifier.
                    'List-Unsubscribe' => $this->headerString($header?->get('list_unsubscribe')),
                    'Precedence' => $this->headerString($header?->get('precedence')),
                    'Auto-Submitted' => $this->headerString($header?->get('auto_submitted')),
                    'X-Mailer' => $this->headerString($header?->get('x_mailer')),
                ],
            ]);

        // Sender → Contact lookup (best-effort; SET NULL if no match).
        if ($fromAddr !== null) {
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

    private function extractMessageId(Message $msg): string
    {
        $id = $this->headerString($msg->getHeader()?->get('message_id'));
        if ($id !== null && $id !== '') {
            return $id;
        }
        // Synthesise a stable ID from UID + folder when the message
        // has no Message-ID header (rare, but happens with some
        // outbound-only relays). Idempotency is then per-folder.
        return sprintf(
            'no-msgid+uid-%d@%s.local',
            (int) $msg->getUid(),
            (string) ($msg->getClient()->getConfig()->get('username', 'unknown')),
        );
    }

    private function headerString(mixed $attr): ?string
    {
        if ($attr === null) {
            return null;
        }
        if (is_string($attr)) {
            $t = trim($attr);
            return $t === '' ? null : $t;
        }
        if (is_object($attr) && method_exists($attr, 'toString')) {
            $t = trim($attr->toString());
            return $t === '' ? null : $t;
        }
        if (is_array($attr) && isset($attr[0])) {
            return $this->headerString($attr[0]);
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function headerArray(mixed $attr): array
    {
        if ($attr === null) {
            return [];
        }
        if (is_array($attr)) {
            return array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : null, $attr)));
        }
        $s = $this->headerString($attr);
        if ($s === null) {
            return [];
        }
        // References can come as a single space-separated string.
        return array_values(array_filter(array_map('trim', explode(' ', $s))));
    }

    private function makeClient(Channel $channel)
    {
        $cfg = $channel->getInboundConfig();
        $auth = $channel->getAuthConfig();

        $cm = new ClientManager();
        return $cm->make([
            'host' => (string) ($cfg['host'] ?? ''),
            'port' => (int) ($cfg['port'] ?? 993),
            'encryption' => (string) ($cfg['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($cfg['validate_cert'] ?? true),
            'username' => (string) ($auth['username'] ?? ''),
            'password' => (string) ($auth['password'] ?? ''),
            'protocol' => 'imap',
        ]);
    }

    // ---- outbound --------------------------------------------------

    public function send(Channel $channel, OutboundMessage $message): OutboundResult
    {
        $outCfg = $channel->getOutboundConfig();
        $auth = $channel->getAuthConfig();

        $host = (string) ($outCfg['host'] ?? '');
        $port = (int) ($outCfg['port'] ?? 587);
        $enc = strtolower((string) ($outCfg['encryption'] ?? 'tls'));
        $user = (string) ($auth['smtpUsername'] ?? $auth['username'] ?? '');
        $pass = (string) ($auth['smtpPassword'] ?? $auth['password'] ?? '');
        $maxOutBytes = (int) ($outCfg['maxOutboundBytes'] ?? self::DEFAULT_MAX_OUTBOUND_BYTES);

        if ($host === '' || $user === '') {
            return OutboundResult::failed('SMTP host or username missing in channel config.');
        }

        // Pre-flight size check — sum body + attachment sizes against
        // the recipient SMTP cap (Gmail 25 MB, Exchange 35 MB by
        // default; the operator can lift this per-channel). Failing
        // early beats the cryptic provider 5xx after a long upload.
        $bodyBytes = \strlen($message->getBody());
        $attBytes = 0;
        foreach ($message->getAttachments() as $att) {
            $attBytes += (int) ($att['sizeBytes'] ?? 0);
        }
        $totalBytes = $bodyBytes + $attBytes;
        if ($totalBytes > $maxOutBytes) {
            return OutboundResult::failed(sprintf(
                'Message size %.1f MB exceeds the channel outbound limit of %.1f MB.',
                $totalBytes / 1024 / 1024,
                $maxOutBytes / 1024 / 1024,
            ));
        }

        // Build a Symfony Mailer DSN per channel. Cleaner than holding
        // a global transport because each channel has its own creds.
        $dsn = sprintf(
            '%s://%s:%s@%s:%d?%s',
            $enc === 'ssl' ? 'smtps' : 'smtp',
            rawurlencode($user),
            rawurlencode($pass),
            $host,
            $port,
            http_build_query([
                'encryption' => $enc === '' ? null : $enc,
                'verify_peer' => $outCfg['validate_cert'] ?? '1',
            ]),
        );

        try {
            $transport = Transport::fromDsn($dsn);

            $email = (new Email())
                ->from(sprintf('%s <%s>',
                    (string) ($outCfg['fromName'] ?? ''),
                    (string) ($outCfg['from'] ?? $user),
                ))
                ->to($message->getRecipientRaw())
                ->subject((string) ($message->getSubject() ?? '(no subject)'))
                ->text($message->getBody());

            // Attachments — pulled from FileStorage at send-time so the
            // OutboundMessage row stays a thin pointer. The attachments
            // array is expected to hold `storedAt` (the FileStorage
            // path) — uploaded files set this when the caller attaches
            // them to the message.
            foreach ($message->getAttachments() as $att) {
                $path = $att['storedAt'] ?? null;
                if (!is_string($path) || $path === '') {
                    continue;
                }
                // FileStorage doesn't expose a path → stream helper that
                // takes an opaque path string; use the underlying
                // filesystem via its readStream. The path was minted
                // by FileStorage itself so it's safe to round-trip.
                $stream = $this->fileStorage->readStreamByPath($path);
                if ($stream === null) {
                    continue;
                }
                $email->attach(
                    stream_get_contents($stream),
                    (string) ($att['filename'] ?? 'attachment.bin'),
                    (string) ($att['mimeType'] ?? 'application/octet-stream'),
                );
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }

            // In-Reply-To / References for thread continuity.
            $inReplyTo = $message->getInReplyToInboundEvent();
            if ($inReplyTo !== null) {
                $email->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo->getExternalId());
                $refs = $inReplyTo->getSourceMetadata()['headers']['References'] ?? null;
                $refsChain = $refs ? trim($refs . ' <' . $inReplyTo->getExternalId() . '>') : '<' . $inReplyTo->getExternalId() . '>';
                $email->getHeaders()->addTextHeader('References', $refsChain);
            }

            $sentMessage = $transport->send($email);
            return OutboundResult::sent($sentMessage->getMessageId() ?: '');
        } catch (\Throwable $e) {
            // Transport-level failures (auth, dns, refused) are
            // typically permanent for this credential set — surface
            // as failed rather than retry so the worker doesn't loop
            // on a broken channel.
            return OutboundResult::failed($e->getMessage());
        }
    }

    public function selfTest(Channel $channel): TestResult
    {
        $auth = $channel->getAuthConfig();
        $cfg = $channel->getInboundConfig();
        if (empty($cfg['host']) || empty($auth['username'])) {
            return TestResult::failed('Host or username missing in channel config.');
        }
        try {
            $client = $this->makeClient($channel);
            $client->connect();
            $folder = $client->getFolder((string) ($cfg['folder'] ?? 'INBOX'));
            $client->disconnect();
            if ($folder === null) {
                return TestResult::warning(sprintf(
                    'IMAP login OK, but folder "%s" was not found — events would be polled from a missing mailbox.',
                    (string) ($cfg['folder'] ?? 'INBOX'),
                ));
            }
            return TestResult::ok('IMAP login + folder access OK.');
        } catch (\Throwable $e) {
            return TestResult::failed(sprintf('IMAP connect failed: %s', $e->getMessage()));
        }
    }
}
