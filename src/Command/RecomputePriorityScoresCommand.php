<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Workspace;
use App\Service\Priority\PriorityScoreCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Materializes the internal priority score onto {@see \App\Entity\Task}
 * (priority_score / _blocked / _parts / _at) so it can be sorted, filtered and
 * shipped in the row payload without an on-demand reports call.
 *
 * The score has time-dependent inputs (card aging, due-date criticality) and
 * cross-task inputs (blocker leverage, customer-revenue percentile), so a whole
 * workspace is recomputed at once. Schedule it nightly (and after
 * app:lexoffice:sync-revenue) via cron.
 *
 *   bin/console worktide:priority:recompute [--workspace=<uuid>] [--dry-run]
 */
#[AsCommand(
    name: 'worktide:priority:recompute',
    description: 'Recompute and store the internal priority score for all tasks (per workspace).',
)]
final class RecomputePriorityScoresCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly PriorityScoreCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace UUID (default: all workspaces)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute and report without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $wsOpt = (string) $input->getOption('workspace');
        if ($wsOpt !== '') {
            try {
                $ws = $this->em->find(Workspace::class, Uuid::fromString($wsOpt));
            } catch (\InvalidArgumentException) {
                $io->error('--workspace is not a valid UUID.');
                return Command::INVALID;
            }
            if (!$ws instanceof Workspace) {
                $io->error('Workspace not found.');
                return Command::INVALID;
            }
            $workspaces = [$ws];
        } else {
            $workspaces = $this->em->getRepository(Workspace::class)->findAll();
        }

        $now = new \DateTimeImmutable();
        $totalWritten = 0;

        foreach ($workspaces as $workspace) {
            $scores = $this->calculator->computeForWorkspace($workspace);
            if ($scores === []) {
                continue;
            }

            if (!$dryRun) {
                $this->db->transactional(function () use ($scores, $now): void {
                    foreach ($scores as $uuid => $data) {
                        $this->db->executeStatement(
                            'UPDATE tasks SET priority_score = :score, priority_score_blocked = :blocked,
                                    priority_score_parts = :parts, priority_score_at = :at
                             WHERE id = :id',
                            [
                                'score' => $data['score'],
                                'blocked' => $data['blocked'] ? 1 : 0,
                                'parts' => json_encode($data['parts'], \JSON_THROW_ON_ERROR),
                                'at' => $now->format('Y-m-d H:i:s'),
                                'id' => Uuid::fromString($uuid)->toBinary(),
                            ],
                            [
                                'score' => ParameterType::INTEGER,
                                'blocked' => ParameterType::INTEGER,
                                'parts' => ParameterType::STRING,
                                'at' => ParameterType::STRING,
                                'id' => ParameterType::BINARY,
                            ],
                        );
                    }
                });
            }

            $totalWritten += \count($scores);
            $io->writeln(sprintf(
                '<info>%s</info>: %d tasks %s',
                $workspace->getName() ?? (string) $workspace->getId(),
                \count($scores),
                $dryRun ? 'to score' : 'scored',
            ));
        }

        $io->success(sprintf('%s %d task scores across %d workspace(s).', $dryRun ? 'Would write' : 'Wrote', $totalWritten, \count($workspaces)));

        return Command::SUCCESS;
    }
}
