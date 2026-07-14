<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\Adapter\Email\EmailImapAdapter;
use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Entity\InboundEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

/**
 * One-off repair: re-fetch the From header from IMAP for inbound events whose
 * sender was lost at ingest (php-imap 6 address-attribute bug — see
 * {@see EmailImapAdapter::senderRawFromHeader()}). The events store their IMAP
 * uid in `sourceMetadata.uid`, so we reconnect with the channel's own
 * (decrypted) credentials, fetch each message by uid, and set `senderRaw` on the
 * event + its conversation.
 *
 * IMAP-only (Gmail/Graph never lost the sender). Idempotent: only events with a
 * NULL senderRaw and a stored uid are touched. Skips messages no longer on the
 * server. Under SEARCH_PROVIDER=meilisearch the SearchIndexingListener re-syncs
 * the updated rows via the `search` worker.
 *
 *   bin/console worktide:mail:backfill-senders [--channel=<uuid>] [--dry-run]
 */
#[AsCommand(
    name: 'worktide:mail:backfill-senders',
    description: 'Re-fetch missing inbound sender (From) values from IMAP for existing events.',
)]
final class MailBackfillSendersCommand extends Command
{
    private const int DEFAULT_BATCH = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EmailImapAdapter $imap,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Restrict to a single channel UUID')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per DB batch', (string) self::DEFAULT_BATCH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count candidate events (no IMAP connection, no writes)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = max(1, (int) $input->getOption('batch'));
        $dryRun = (bool) $input->getOption('dry-run');

        $channels = $this->collectImapChannels($input->getOption('channel'));
        if ($channels === []) {
            $io->writeln('<comment>No matching email_imap channels.</comment>');

            return Command::SUCCESS;
        }

        $grandUpdated = 0;
        foreach ($channels as $channel) {
            $io->section(sprintf('%s [%s]', $channel->getName(), $channel->getAdapterCode()));
            $grandUpdated += $dryRun
                ? $this->reportCandidates($channel, $io)
                : $this->backfillChannel($channel, $batch, $io);
        }

        $io->success(sprintf('%s %d event(s).', $dryRun ? 'Would backfill' : 'Backfilled senders on', $grandUpdated));

        return Command::SUCCESS;
    }

    private function reportCandidates(Channel $channel, SymfonyStyle $io): int
    {
        $n = (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(InboundEvent::class, 'e')
            ->where('e.channel = :ch')->setParameter('ch', $channel->getId(), 'uuid')
            ->andWhere('e.senderRaw IS NULL')
            ->getQuery()->getSingleScalarResult();
        $io->writeln(sprintf('  %d candidate event(s) with no sender', $n));

        return $n;
    }

    private function backfillChannel(Channel $channel, int $batch, SymfonyStyle $io): int
    {
        $folderName = (string) ($channel->getInboundConfig()['folder'] ?? 'INBOX');
        $channelId = $channel->getId();

        try {
            $client = $this->imap->makeClient($channel);
            $client->connect();
        } catch (\Throwable $e) {
            $io->error(sprintf('Connect failed: %s', $e->getMessage()));

            return 0;
        }

        $updated = 0;
        $missing = 0;
        $failed = 0;
        $lastId = null;
        try {
            $folder = $client->getFolder($folderName);
            if ($folder === null) {
                $io->error(sprintf('Folder "%s" not found.', $folderName));

                return 0;
            }

            while (true) {
                $qb = $this->em->createQueryBuilder()
                    ->select('e')
                    ->from(InboundEvent::class, 'e')
                    ->where('e.channel = :ch')->setParameter('ch', $channelId, 'uuid')
                    ->andWhere('e.senderRaw IS NULL')
                    ->orderBy('e.id', 'ASC')
                    ->setMaxResults($batch);
                if ($lastId !== null) {
                    $qb->andWhere('e.id > :last')->setParameter('last', $lastId, 'uuid');
                }
                /** @var list<InboundEvent> $events */
                $events = $qb->getQuery()->getResult();
                if ($events === []) {
                    break;
                }

                foreach ($events as $event) {
                    $id = $event->getId();
                    if ($id instanceof Uuid) {
                        $lastId = $id;
                    }
                    $uid = $event->getSourceMetadata()['uid'] ?? null;
                    if (!is_int($uid) && !(is_string($uid) && ctype_digit($uid))) {
                        continue; // no uid to fetch by (non-IMAP shape / stub)
                    }
                    // Per-message fetch is fault-isolated: a single unreadable
                    // message ("no headers found", server hiccup) must not abort
                    // the whole channel — count it and move on.
                    $sender = null;
                    $gone = false;
                    try {
                        $msg = $folder->messages()->getMessageByUid((int) $uid);
                        if ($msg === null) {
                            $gone = true;
                        } else {
                            $sender = $this->imap->senderRawFromHeader($msg->getHeader()?->get('from'));
                        }
                    } catch (\Throwable) {
                        // php-imap's message parser choked (malformed/old headers).
                        // Fall through to the raw-header fallback below.
                    }
                    // Fallback: fetch the raw From header straight off the wire,
                    // bypassing the message parser that just failed.
                    if ($sender === null && !$gone) {
                        $sender = $this->rawSenderByUid($client, (int) $uid);
                    }
                    if ($gone) {
                        ++$missing; // no longer on the server

                        continue;
                    }
                    if ($sender === null) {
                        ++$failed; // fetched but no usable From (parser + raw both empty)

                        continue;
                    }
                    $event->setSenderRaw(mb_substr($sender, 0, 200));
                    $conversation = $event->getConversation();
                    if ($conversation !== null && ($conversation->getSenderRaw() === null || $conversation->getSenderRaw() === '')) {
                        $conversation->setSenderRaw(mb_substr($sender, 0, 200));
                    }
                    ++$updated;
                }

                $this->em->flush();
                $this->em->clear();
            }
        } catch (\Throwable $e) {
            $io->error(sprintf('Backfill aborted after %d update(s): %s', $updated, $e->getMessage()));
        } finally {
            $client->disconnect();
        }

        $io->writeln(sprintf('  %d sender(s) restored', $updated));
        if ($failed > 0 || $missing > 0) {
            $io->writeln(sprintf('  (%d unreadable, %d gone from server — skipped)', $failed, $missing));
        }

        return $updated;
    }

    /**
     * @return list<Channel>
     */
    private function collectImapChannels(mixed $only): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Channel::class, 'c')
            ->where('c.adapterCode = :code')->setParameter('code', EmailImapAdapter::CODE)
            ->andWhere('c.isEnabled = 1')
            ->andWhere('c.deletedAt IS NULL');

        if (is_string($only) && $only !== '') {
            try {
                $qb->andWhere('c.id = :id')->setParameter('id', Uuid::fromString($only), 'uuid');
            } catch (\InvalidArgumentException) {
                return [];
            }
        }

        /** @var list<Channel> $channels */
        $channels = $qb->getQuery()->getResult();

        return array_values(array_filter($channels, static fn (Channel $c) => $c->supports(ChannelCapability::Inbound)));
    }

    /**
     * Fallback for messages php-imap's parser can't read ("no headers found"):
     * fetch the raw RFC822 header block off the wire by uid and pull the From
     * line out ourselves. Returns the composed sender or null when even the raw
     * header has no usable From.
     */
    private function rawSenderByUid(Client $client, int $uid): ?string
    {
        try {
            $data = $client->getConnection()->headers([$uid], 'RFC822', IMAP::ST_UID)->data();
        } catch (\Throwable) {
            return null;
        }

        $raw = null;
        if (is_array($data)) {
            $raw = $data[$uid] ?? (is_array($data) && $data !== [] ? reset($data) : null);
        } elseif (is_string($data)) {
            $raw = $data;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // Unfold header continuation lines (CRLF + leading WSP → single space),
        // then take the first From: line.
        $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $raw);
        if (preg_match('/^From:\s*(.+)$/im', $unfolded, $m) !== 1) {
            return null;
        }

        return $this->parseFromLine(trim($m[1]));
    }

    /**
     * "=?utf-8?…?= <a@b>" / "Name <a@b>" / "<a@b>" / "a@b" → "Name <a@b>" or the
     * bare address, MIME-decoding the display name.
     */
    private function parseFromLine(string $from): ?string
    {
        if ($from === '') {
            return null;
        }
        $decoded = trim(str_contains($from, '=?') ? (@mb_decode_mimeheader($from) ?: $from) : $from);
        if (preg_match('/^(.*?)<([^>]+)>\s*$/', $decoded, $m) === 1) {
            $name = trim(trim($m[1]), " \"'");
            $addr = trim($m[2]);

            return $name !== '' ? sprintf('%s <%s>', $name, $addr) : ($addr !== '' ? $addr : null);
        }

        return $decoded !== '' ? $decoded : null;
    }
}
