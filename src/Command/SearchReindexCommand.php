<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Search\SearchDocumentFactory;
use App\Service\Search\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * (Re)builds the search index from the database — bootstrap + after schema/mapping
 * changes. Walks each searchable entity in batches and pushes documents to the
 * active provider. No-op under SEARCH_PROVIDER=mysql (that provider reads the DB
 * live). Mirrors the batching of the sync/backfill commands.
 *
 *   bin/console worktide:search:reindex [--resource=task,conversation] [--batch=500]
 */
#[AsCommand(
    name: 'worktide:search:reindex',
    description: 'Rebuild the full-text search index from the database.',
)]
final class SearchReindexCommand extends Command
{
    private const int DEFAULT_BATCH = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SearchProviderInterface $provider,
        private readonly SearchDocumentFactory $factory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('resource', null, InputOption::VALUE_REQUIRED, 'Comma-separated type slugs to index (default: all)')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per batch', (string) self::DEFAULT_BATCH);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->provider->requiresIndexing()) {
            $io->warning('Active search provider needs no index (SEARCH_PROVIDER=mysql reads the DB live). Nothing to do.');

            return Command::SUCCESS;
        }
        if (!$this->provider->isAvailable()) {
            $io->error('Search backend is not reachable — check MEILISEARCH_DSN and that the service is up.');

            return Command::FAILURE;
        }

        $only = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('resource')))));
        $batch = max(1, (int) $input->getOption('batch'));

        $grandTotal = 0;
        foreach ($this->factory->searchableClasses() as $class) {
            $type = $this->factory->typeForClass($class);
            if ($type === null || ($only !== [] && !\in_array($type, $only, true))) {
                continue;
            }

            $io->section($type);
            $repo = $this->em->getRepository($class);
            $offset = 0;
            $indexed = 0;
            while (true) {
                $rows = $repo->findBy([], null, $batch, $offset);
                if ($rows === []) {
                    break;
                }
                $docs = [];
                foreach ($rows as $entity) {
                    $doc = $this->factory->build($entity);
                    if ($doc !== null) {
                        $docs[] = $doc;
                    }
                }
                if ($docs !== []) {
                    $this->provider->reindex($docs);
                    $indexed += \count($docs);
                }
                $offset += \count($rows);
                $this->em->clear(); // keep memory flat across batches
                if (\count($rows) < $batch) {
                    break;
                }
            }
            $io->writeln(sprintf('  %d indexed', $indexed));
            $grandTotal += $indexed;
        }

        $io->success(sprintf('Reindexed %d document(s).', $grandTotal));

        return Command::SUCCESS;
    }
}
