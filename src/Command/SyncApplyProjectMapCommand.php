<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use App\Service\Inbound\ProjectMappingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Applies a reviewed project-mapping file (from worktide:sync:map-projects):
 * persists the Redmine→worktide project links as EntitySync(entityType='project',
 * Inbound), creates projects for "new" entries, and mirrors the external id into
 * the "Redmine-Projekt-ID" custom field. Idempotent.
 *
 *   bin/console worktide:sync:apply-project-map --channel=<uuid> [--map=var/redmine-project-map.json] [--dry-run]
 *
 * Run this after editing the map; then worktide:sync:seed-import --routed uses it.
 */
#[AsCommand(
    name: 'worktide:sync:apply-project-map',
    description: 'Persist a reviewed Redmine→worktide project mapping (EntitySync + custom field).',
)]
final class SyncApplyProjectMapCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectMappingService $mapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Redmine sync channel UUID')
            ->addOption('map', null, InputOption::VALUE_REQUIRED, 'Reviewed mapping JSON path', 'var/redmine-project-map.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $channelId = (string) $input->getOption('channel');
        try {
            $channel = $channelId !== '' ? $this->em->find(Channel::class, Uuid::fromString($channelId)) : null;
        } catch (\InvalidArgumentException) {
            $io->error('--channel is not a valid UUID.');
            return Command::INVALID;
        }
        if (!$channel instanceof Channel) {
            $io->error('Channel not found.');
            return Command::INVALID;
        }

        $mapPath = (string) $input->getOption('map');
        $absolute = str_starts_with($mapPath, '/') ? $mapPath : getcwd() . '/' . $mapPath;
        if (!is_readable($absolute)) {
            $io->error('Mapping file not readable: ' . $absolute);
            return Command::INVALID;
        }
        $entries = json_decode((string) file_get_contents($absolute), true);
        if (!\is_array($entries)) {
            $io->error('Mapping file is not a JSON array.');
            return Command::INVALID;
        }

        $baseUrl = (string) ($channel->getInboundConfig()['baseUrl'] ?? '');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $r = $this->mapper->applyMap($channel, $entries, $baseUrl, $dryRun);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s — linked %d existing, created %d new, skipped %d.',
            $dryRun ? 'Dry run' : 'Project mapping applied',
            $r['linked'],
            $r['created'],
            $r['skipped'],
        ));

        return Command::SUCCESS;
    }
}
