<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AgreementType;
use App\Entity\Workspace;
use App\Repository\AgreementTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds the standard CRM agreement types (SLA, AV-Vertrag/DPA,
 * Geheimhaltungsvereinbarung/NDA) for one or all workspaces. Idempotent —
 * existing slugs are left untouched, so it's safe to re-run after adding
 * new workspaces.
 *
 *   bin/console app:crm:seed-agreement-types --workspace=<uuid>
 *   bin/console app:crm:seed-agreement-types --workspace=all
 */
#[AsCommand(
    name: 'app:crm:seed-agreement-types',
    description: 'Seed standard agreement types (SLA, AV, NDA) into a workspace.',
)]
final class CrmSeedAgreementTypesCommand extends Command
{
    /** @var list<array{slug: string, name: string, mandatory: bool, position: float}> */
    private const DEFAULTS = [
        ['slug' => 'sla', 'name' => 'Service Level Agreement (SLA)', 'mandatory' => false, 'position' => 1.0],
        ['slug' => 'av', 'name' => 'Auftragsverarbeitungs-Vertrag (AV)', 'mandatory' => true, 'position' => 2.0],
        ['slug' => 'nda', 'name' => 'Geheimhaltungsvereinbarung (NDA)', 'mandatory' => false, 'position' => 3.0],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgreementTypeRepository $types,
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
            foreach (self::DEFAULTS as $def) {
                if ($this->types->findOneBySlug($ws, $def['slug']) !== null) {
                    continue;
                }
                $type = (new AgreementType())
                    ->setName($def['name'])
                    ->setSlug($def['slug'])
                    ->setIsMandatory($def['mandatory'])
                    ->setPosition($def['position']);
                $type->setWorkspace($ws);
                $this->em->persist($type);
                ++$created;
            }
        }
        $this->em->flush();

        $io->success(\sprintf('Seeded %d agreement type(s) across %d workspace(s).', $created, \count($workspaces)));

        return Command::SUCCESS;
    }
}
