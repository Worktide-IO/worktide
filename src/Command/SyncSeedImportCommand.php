<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use App\Entity\Project;
use App\Service\Inbound\SyncSeedImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * One-shot bulk import of a sync channel's external records into local Tasks —
 * the deliberate "fill the system with live data" step.
 *
 *   bin/console worktide:sync:seed-import --channel=<uuid> --project=<uuid> [--limit=N] [--dry-run]
 *
 * Pulls from Redmine/Jira and creates Task + EntitySync (Bidirectional) per
 * record. Idempotent — re-runs skip records already mapped. Inbound only:
 * outbound push stays withheld by the EgressGuard (`ticket_push`) until approved,
 * so seeding never causes data to leave the system.
 */
#[AsCommand(
    name: 'worktide:sync:seed-import',
    description: 'Bulk-import a sync channel\'s external records into local Tasks (live-data seed).',
)]
final class SyncSeedImportCommand extends Command
{
    public function __construct(
        private readonly SyncSeedImporter $importer,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Sync channel UUID (adapterCode redmine/jira)')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Target project UUID to import tasks into')
            ->addOption('routed', null, InputOption::VALUE_NONE, 'Route each issue to the project its Redmine project maps to (EntitySync); requires apply-project-map first')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max records to import this run')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Pull and report counts without writing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $channel = $this->resolve(Channel::class, $input->getOption('channel'), $io, 'channel');
        if ($channel === null) {
            return Command::INVALID;
        }

        $limitOpt = $input->getOption('limit');
        $limit = $limitOpt !== null ? max(1, (int) $limitOpt) : null;
        $dryRun = (bool) $input->getOption('dry-run');
        $routed = (bool) $input->getOption('routed');

        try {
            if ($routed) {
                $result = $this->importer->seedRouted($channel, $limit, $dryRun);
            } else {
                $project = $this->resolve(Project::class, $input->getOption('project'), $io, 'project');
                if ($project === null) {
                    return Command::INVALID;
                }
                $result = $this->importer->seed($channel, $project, $limit, $dryRun);
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s — pulled %d, %s %d, skipped %d%s.',
            $dryRun ? 'Dry run' : 'Seed import done',
            $result['total'],
            $dryRun ? 'would import' : 'imported',
            $result['created'],
            $result['skipped'],
            isset($result['unmapped']) ? sprintf(', unmapped %d', $result['unmapped']) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private function resolve(string $class, mixed $id, SymfonyStyle $io, string $label): ?object
    {
        if (!\is_string($id) || $id === '') {
            $io->error(sprintf('Missing --%s=<uuid>.', $label));
            return null;
        }
        try {
            $entity = $this->em->find($class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $io->error(sprintf('--%s is not a valid UUID.', $label));
            return null;
        }
        if ($entity === null) {
            $io->error(sprintf('No %s found for %s.', $label, $id));
            return null;
        }

        return $entity;
    }
}
