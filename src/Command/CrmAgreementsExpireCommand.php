<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CustomerAgreementRepository;
use App\Service\Crm\AgreementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Flips signed customer agreements whose `validUntil` has passed to `expired`,
 * so the denormalised head status stays filterable. Idempotent — meant to run
 * daily (see the ddev cron). Recompute is delegated to {@see AgreementService}
 * so the logic matches the interactive path.
 */
#[AsCommand(
    name: 'app:crm:agreements:expire',
    description: 'Mark signed agreements whose validity has lapsed as expired.',
)]
final class CrmAgreementsExpireCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerAgreementRepository $heads,
        private readonly AgreementService $agreements,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = new \DateTimeImmutable('today');

        $lapsed = $this->heads->findExpiring($today);
        foreach ($lapsed as $head) {
            $this->agreements->recompute($head, $today);
        }
        if ($lapsed !== []) {
            $this->em->flush();
        }

        $io->success(\sprintf('%d agreement(s) marked expired.', \count($lapsed)));

        return Command::SUCCESS;
    }
}
