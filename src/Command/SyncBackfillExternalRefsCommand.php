<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\EntityTypeResolver;
use App\Entity\Channel;
use App\Entity\EntitySync;
use App\Entity\Enum\SyncMode;
use App\Entity\Workspace;
use App\Repository\EntitySyncRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills the legacy single-slot external references (ExternalReferenceTrait:
 * externalSource/externalId) into first-class {@see EntitySync} rows, so a
 * record's external identity becomes multi-system capable (awork AND lexoffice
 * AND …) instead of one fixed slot.
 *
 * Creates a disabled anchor Channel per (workspace, source) to hang the
 * mappings on, then a Disabled/Inbound EntitySync per record. Idempotent —
 * guarded by EntitySync's UNIQUE(channel, external_id).
 *
 *   bin/console app:sync:backfill-external-refs --source=awork [--dry-run]
 */
#[AsCommand(
    name: 'app:sync:backfill-external-refs',
    description: 'Mirror legacy externalSource/externalId slots into EntitySync mappings.',
)]
final class SyncBackfillExternalRefsCommand extends Command
{
    /** Entity types to backfill (must be known to the resolver). */
    private const TYPES = ['customer', 'contact'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntityTypeResolver $resolver,
        private readonly EntitySyncRepository $syncs,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'externalSource to backfill', 'awork')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = (string) $input->getOption('source');
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var array<string, Channel> $channelByWs anchor channel per workspace uuid */
        $channelByWs = [];
        $created = 0;
        $skipped = 0;

        foreach (self::TYPES as $type) {
            $class = $this->resolver->classFor($type);
            $repo = $this->em->getRepository($class);
            $rows = $repo->findBy(['externalSource' => $source]);
            foreach ($rows as $entity) {
                if (method_exists($entity, 'isDeleted') && $entity->isDeleted()) {
                    continue;
                }
                $externalId = $entity->getExternalId();
                $id = $entity->getId();
                if ($externalId === null || $id === null) {
                    continue;
                }
                $workspace = $entity->getWorkspace();
                $channel = $channelByWs[$workspace->getId()?->toRfc4122() ?? ''] ??= $this->anchorChannel($workspace, $source, $dryRun);

                // Idempotent: UNIQUE(channel, externalId).
                if ($this->syncs->findOneBy(['channel' => $channel, 'externalId' => $externalId]) !== null) {
                    ++$skipped;
                    continue;
                }
                if (!$dryRun) {
                    $sync = (new EntitySync())
                        ->setChannel($channel)
                        ->setEntityType($type)
                        ->setEntityId($id)
                        ->setExternalId($externalId)
                        ->setSyncMode(SyncMode::Inbound);
                    $sync->setWorkspace($workspace);
                    $this->em->persist($sync);
                }
                ++$created;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s — %d EntitySync-Mappings %s, %d bereits vorhanden (Quelle: %s).',
            $dryRun ? 'Dry run' : 'Backfill',
            $created,
            $dryRun ? 'würden angelegt' : 'angelegt',
            $skipped,
            $source,
        ));

        return Command::SUCCESS;
    }

    private function anchorChannel(Workspace $workspace, string $source, bool $dryRun): Channel
    {
        $repo = $this->em->getRepository(Channel::class);
        $existing = $repo->findOneBy(['workspace' => $workspace, 'adapterCode' => $source]);
        if ($existing instanceof Channel) {
            return $existing;
        }
        $channel = (new Channel())
            ->setName(ucfirst($source) . ' (Import)')
            ->setAdapterCode($source)
            ->setCapabilities(['inbound'])
            ->setEntityTypes(self::TYPES)
            ->setIsEnabled(false)
            ->setIsShared(false);
        $channel->setWorkspace($workspace);
        if (!$dryRun) {
            $this->em->persist($channel);
            $this->em->flush(); // needs an id for the EntitySync FK
        }

        return $channel;
    }
}
