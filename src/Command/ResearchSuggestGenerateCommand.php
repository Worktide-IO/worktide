<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Workspace;
use App\Service\Ai\ResearchSuggestionGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Generates proactive research suggestions per workspace: it reads the workspace
 * business snapshot and writes a few Pending {@see \App\Entity\AIRecommendation}
 * (kind ResearchSuggestion) a human can accept into a mission. Deduped — a
 * workspace with pending suggestions is skipped — so it is safe to run nightly
 * via cron.
 *
 *   bin/console worktide:research:suggest [--workspace=<uuid>]
 */
#[AsCommand(
    name: 'worktide:research:suggest',
    description: 'Generate proactive research-mission suggestions for workspaces (human-in-the-loop).',
)]
final class ResearchSuggestGenerateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResearchSuggestionGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace UUID (default: all workspaces)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->generator->isAvailable()) {
            $io->warning('LLM is not configured or "llm" egress is not approved — nothing to do.');

            return Command::SUCCESS;
        }

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

        $total = 0;
        foreach ($workspaces as $workspace) {
            $created = $this->generator->generateForWorkspace($workspace);
            $total += $created;
            if ($created > 0) {
                $io->text(sprintf('%s: %d suggestion(s)', $workspace->getName(), $created));
            }
        }

        $io->success(sprintf('Created %d research suggestion(s) across %d workspace(s).', $total, \count($workspaces)));

        return Command::SUCCESS;
    }
}
