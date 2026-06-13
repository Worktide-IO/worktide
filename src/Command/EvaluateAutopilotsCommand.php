<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Autopilot\AutopilotEvaluator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Evaluates every enabled Autopilot's rules and emits domain events for
 * any rule that triggered. Designed to run from cron / systemd-timer:
 *
 *   0,15,30,45 * * * * cd /var/www/worktide && bin/console app:autopilot:evaluate
 *   (every 15 minutes; written out so this docblock doesn't choke on the
 *    star-slash sequence)
 *
 * Idempotent in the sense that rules that fired in a prior run will fire
 * again if their condition still holds — downstream consumers (B10 webhook
 * delivery, future notifications) should de-dupe based on payload+date if
 * they want to avoid noise.
 */
#[AsCommand(
    name: 'app:autopilot:evaluate',
    description: 'Evaluate every enabled Autopilot rule and emit alert events.',
)]
final class EvaluateAutopilotsCommand extends Command
{
    public function __construct(
        private readonly AutopilotEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->evaluator->evaluateAll();

        if ($result['fired'] === []) {
            $io->info(sprintf('Evaluated %d autopilots, no rules triggered.', $result['evaluated']));
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Evaluated %d autopilots; %d rules triggered:', $result['evaluated'], $result['triggered']));
        foreach ($result['fired'] as $fire) {
            $io->writeln(sprintf(
                '  · [%s] %s: %s',
                $fire['projectKey'],
                $fire['kind'],
                json_encode($fire['payload'], JSON_THROW_ON_ERROR),
            ));
        }
        return Command::SUCCESS;
    }
}
