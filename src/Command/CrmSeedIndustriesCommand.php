<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Industry;
use App\Entity\Workspace;
use App\Repository\IndustryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds a standard list of industries ("Branchen") for one or all workspaces.
 * Idempotent — existing names are left untouched. Safe to re-run.
 *
 *   bin/console app:crm:seed-industries --workspace=<uuid>|all
 */
#[AsCommand(
    name: 'app:crm:seed-industries',
    description: 'Seed a standard list of industries into a workspace.',
)]
final class CrmSeedIndustriesCommand extends Command
{
    /** @var list<string> ordered */
    private const DEFAULTS = [
        'IT & Software',
        'Beratung & Dienstleistungen',
        'Handel & E-Commerce',
        'Industrie & Produktion',
        'Handwerk & Bau',
        'Gesundheit & Pflege',
        'Bildung & Wissenschaft',
        'Öffentliche Verwaltung',
        'Finanzen & Versicherungen',
        'Immobilien',
        'Medien, Marketing & Werbung',
        'Tourismus & Gastronomie',
        'Transport & Logistik',
        'Energie & Umwelt',
        'Recht & Steuern',
        'Verein & Non-Profit',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IndustryRepository $industries,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace UUID, or "all"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = (string) $input->getOption('workspace');
        if ($target === '') {
            $io->error('--workspace=<uuid|all> is required.');

            return Command::INVALID;
        }

        $repo = $this->em->getRepository(Workspace::class);
        if ($target === 'all') {
            $workspaces = $repo->findAll();
        } else {
            try {
                $ws = $repo->find(Uuid::fromString($target));
            } catch (\InvalidArgumentException) {
                $io->error('--workspace is not a valid UUID.');

                return Command::INVALID;
            }
            if (!$ws instanceof Workspace) {
                $io->error('Workspace not found.');

                return Command::INVALID;
            }
            $workspaces = [$ws];
        }

        $created = 0;
        foreach ($workspaces as $ws) {
            $position = 0.0;
            foreach (self::DEFAULTS as $name) {
                $position += 10.0;
                if ($this->industries->findOneByName($ws, $name) !== null) {
                    continue;
                }
                $industry = (new Industry())->setName($name)->setPosition($position);
                $industry->setWorkspace($ws);
                $this->em->persist($industry);
                ++$created;
            }
        }
        $this->em->flush();

        $io->success(\sprintf('Seeded %d industries across %d workspace(s).', $created, \count($workspaces)));

        return Command::SUCCESS;
    }
}
