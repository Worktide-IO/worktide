<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Workspace;
use App\Service\Ai\ProactiveMarketingGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Scans the product catalogue for marketing-worthy events (new releases, untapped
 * features, stale copy) and generates Pending {@see \App\Entity\AIRecommendation}
 * social-copy drafts per product. Deduped — products with pending suggestions
 * are skipped — so it is safe to run nightly via cron.
 *
 *   bin/console worktide:marketing:suggest [--workspace=<uuid>]
 */
#[AsCommand(
    name: 'worktide:marketing:suggest',
    description: 'Generate proactive marketing social-copy suggestions from the product catalogue.',
)]
final class MarketingSuggestCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProactiveMarketingGenerator $generator,
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
                $io->text(sprintf('%s: %d marketing draft(s)', $workspace->getName(), $created));
            }
        }

        $io->success(sprintf('Created %d marketing suggestion(s) across %d workspace(s).', $total, \count($workspaces)));

        return Command::SUCCESS;
    }
}
