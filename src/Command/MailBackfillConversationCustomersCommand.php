<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Conversation;
use App\Service\Inbound\ContactResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills the customer link on conversations that have none, by re-running the
 * same sender→Contact→Customer resolution the {@see ContactResolver} applies at
 * ingest — but over the existing backlog. Threads created before the resolver
 * was wired (or whose sender was only backfilled later via
 * {@see MailBackfillSendersCommand}) never got their customer set; this repairs
 * them so the Inbox can badge each conversation with its customer.
 *
 * Only fills NULL customer links (never overwrites), so it is idempotent and
 * safe to re-run. Dry-run by default — pass --apply to write. Under
 * SEARCH_PROVIDER=meilisearch the SearchIndexingListener re-syncs the updated
 * rows via the `search` worker.
 *
 *   bin/console worktide:mail:backfill-conversation-customers [--apply] [--batch=200]
 */
#[AsCommand(
    name: 'worktide:mail:backfill-conversation-customers',
    description: 'Assign customers to conversations that have none (sender email → Contact → Customer).',
)]
final class MailBackfillConversationCustomersCommand extends Command
{
    private const int DEFAULT_BATCH = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContactResolver $contactResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the assignments (default: dry-run).')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per DB batch', (string) self::DEFAULT_BATCH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $batch = max(1, (int) $input->getOption('batch'));

        // Collect the candidate ids up front: threads with no customer yet but
        // a stored sender to match against. Snapshotting the ids means --apply
        // (which drops rows out of the "customer IS NULL" set) can't cause
        // offset drift, and unmatched rows can't loop a page-0 re-read.
        /** @var list<\Symfony\Component\Uid\Uuid> $ids */
        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(Conversation::class, 'c')
            ->where('c.customer IS NULL')
            ->andWhere('c.senderRaw IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        if ($ids === []) {
            $io->success('No conversations to resolve — all either have a customer or no sender.');

            return Command::SUCCESS;
        }

        $matched = 0;
        $scanned = 0;
        foreach (array_chunk($ids, $batch) as $chunk) {
            /** @var list<Conversation> $rows */
            $rows = $this->em->getRepository(Conversation::class)->findBy(['id' => $chunk]);
            foreach ($rows as $conversation) {
                ++$scanned;
                $contact = $this->contactResolver->resolveForConversation($conversation);
                if ($contact === null) {
                    continue;
                }
                ++$matched;
                $io->writeln(sprintf(
                    '  <info>%s</info>  %s → %s',
                    $conversation->getSenderRaw(),
                    $contact->getCustomer()?->getName() ?? '(no customer)',
                    $conversation->getSubject() !== '' ? $conversation->getSubject() : '(no subject)',
                ), OutputInterface::VERBOSITY_VERBOSE);
            }

            if ($apply) {
                $this->em->flush();
            }
            // Detach the batch so a long backlog doesn't exhaust memory.
            $this->em->clear();
        }

        $mode = $apply ? 'assigned' : 'would assign';
        $io->success(sprintf('Scanned %d conversation(s); %s a customer to %d.', $scanned, $mode, $matched));
        if (!$apply && $matched > 0) {
            $io->note('Dry-run — re-run with --apply to persist.');
        }

        return Command::SUCCESS;
    }
}
