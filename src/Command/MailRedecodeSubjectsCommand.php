<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Conversation;
use App\Entity\InboundEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * One-off data fix: decode RFC 2047 MIME encoded-words ("=?utf-8?B?…?=") left
 * in stored inbound subjects / sender names before the mail adapters started
 * decoding them at ingest. Walks InboundEvent + Conversation by keyset (id) in
 * batches, decodes in place, and clears the EM between batches to stay flat.
 *
 * Re-pulling the mailbox does NOT fix these — the pull is idempotent on
 * (channel, external_id), so existing rows are never rewritten. Run this once.
 *
 * Under SEARCH_PROVIDER=meilisearch the SearchIndexingListener re-syncs each
 * updated row to the index via the `search` worker; under mysql there is no
 * index to update.
 *
 *   bin/console worktide:mail:redecode-subjects [--dry-run] [--batch=500]
 */
#[AsCommand(
    name: 'worktide:mail:redecode-subjects',
    description: 'Decode RFC 2047 MIME encoded-words in stored inbound subjects/senders.',
)]
final class MailRedecodeSubjectsCommand extends Command
{
    private const int DEFAULT_BATCH = 500;

    /** LIKE pattern matching any RFC 2047 encoded-word. */
    private const string MIME_LIKE = '%=?%?=%';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per batch', (string) self::DEFAULT_BATCH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = max(1, (int) $input->getOption('batch'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run — no rows will be written.');
        }

        $total = 0;
        foreach ([InboundEvent::class => 'inbound_events', Conversation::class => 'conversations'] as $class => $label) {
            $io->section($label);
            $n = $this->processClass($class, $batch, $dryRun, $io);
            $io->writeln(sprintf('  %d row(s) %s', $n, $dryRun ? 'would change' : 'updated'));
            $total += $n;
        }

        $io->success(sprintf('%s %d row(s).', $dryRun ? 'Would fix' : 'Fixed', $total));

        return Command::SUCCESS;
    }

    /**
     * @param class-string $class
     */
    private function processClass(string $class, int $batch, bool $dryRun, SymfonyStyle $io): int
    {
        $changed = 0;
        $lastId = null;

        while (true) {
            $qb = $this->em->createQueryBuilder()
                ->select('e')
                ->from($class, 'e')
                ->where('e.subject LIKE :p OR e.senderRaw LIKE :p')
                ->setParameter('p', self::MIME_LIKE)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults($batch);
            // Keyset paging by id: decoded rows drop out of the LIKE filter, but
            // walking past the last id still visits every remaining row exactly
            // once (and can't loop on a value that decodes to itself).
            if ($lastId !== null) {
                $qb->andWhere('e.id > :last')->setParameter('last', $lastId, 'uuid');
            }

            /** @var list<InboundEvent|Conversation> $rows */
            $rows = $qb->getQuery()->getResult();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $entity) {
                $id = $entity->getId();
                if ($id instanceof Uuid) {
                    $lastId = $id;
                }

                $dirty = false;

                $subject = $entity->getSubject();
                $newSubject = $this->decode($subject);
                if ($newSubject !== null && $newSubject !== $subject) {
                    if (!$dryRun) {
                        $entity->setSubject(mb_substr($newSubject, 0, 250));
                    }
                    $dirty = true;
                }

                $sender = $entity->getSenderRaw();
                $newSender = $this->decode($sender);
                if ($newSender !== null && $newSender !== $sender) {
                    if (!$dryRun) {
                        $entity->setSenderRaw(mb_substr($newSender, 0, 200));
                    }
                    $dirty = true;
                }

                if ($dirty) {
                    ++$changed;
                }
            }

            if (!$dryRun) {
                $this->em->flush();
            }
            $this->em->clear();

            if (\count($rows) < $batch) {
                break;
            }
        }

        return $changed;
    }

    /**
     * Decode RFC 2047 encoded-words to plain UTF-8; idempotent on plain text.
     */
    private function decode(?string $value): ?string
    {
        if ($value === null || $value === '' || !str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @mb_decode_mimeheader($value);

        return $decoded !== '' ? $decoded : $value;
    }
}
