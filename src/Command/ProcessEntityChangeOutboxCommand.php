<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\AdapterRegistry;
use App\Channels\EntityChange;
use App\Channels\SyncResult;
use App\Entity\EntityChangeOutbox;
use App\Entity\EntitySync;
use App\Entity\Enum\EntityChangeOutboxStatus;
use App\Repository\EntityChangeOutboxRepository;
use App\Repository\EntitySyncRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Worker that drains the EntityChangeOutbox.
 *
 *   bin/console worktide:entity-sync:process [--limit=25]
 *
 * For each claimable row:
 *   1. Mark as Sending so a parallel worker run doesn't double-process
 *   2. Resolve every EntitySync mapping for the (entityType, entityId)
 *   3. For each mapping → adapter.pushEntity(mapping, EntityChange)
 *   4. Persist per-mapping outcome + advance status FSM
 *
 * Cron'able at any frequency — the claim+status update is
 * transactional, so two parallel runs can't ship the same change
 * twice. Backoff on failure is exponential up to 5 attempts; beyond
 * that the row drops to Failed for manual intervention.
 *
 * Symfony-Messenger transport could replace this CLI later — the
 * outbox table stays as the durable queue, Messenger just becomes
 * the pickup mechanism.
 */
#[AsCommand(
    name: 'worktide:entity-sync:process',
    description: 'Drain the EntityChangeOutbox and push changes to mapped external systems.',
)]
final class ProcessEntityChangeOutboxCommand extends Command
{
    /** Cap so a wedged adapter doesn't loop forever. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly EntityChangeOutboxRepository $outbox,
        private readonly EntitySyncRepository $mappings,
        private readonly AdapterRegistry $registry,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows to process this run', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        $rows = $this->outbox->findClaimableBatch($limit);
        if ($rows === []) {
            $io->writeln('<comment>Nothing to process.</comment>');
            return Command::SUCCESS;
        }

        $totalSent = 0;
        $totalConflicts = 0;
        $totalFailed = 0;

        foreach ($rows as $row) {
            $row->setStatus(EntityChangeOutboxStatus::Sending);
            $this->em->flush();

            $mappings = $this->mappings->findByEntity($row->getEntityType(), $row->getEntityId());
            if ($mappings === []) {
                // The entity isn't mirrored — terminal Sent (nothing to do).
                $row->setStatus(EntityChangeOutboxStatus::Sent);
                $row->setProcessedAt(new \DateTimeImmutable());
                $this->em->flush();
                continue;
            }

            $change = new EntityChange(
                entityType: $row->getEntityType(),
                entityId: $row->getEntityId(),
                changedFields: $row->getChangedFields(),
                previousValues: $row->getPreviousValues(),
                isDelete: $row->isDelete(),
            );
            $state = $row->getPerMappingState();
            $hadFailure = false;
            $hadConflict = false;

            foreach ($mappings as $mapping) {
                $mid = $mapping->getId()?->toRfc4122() ?? 'unknown';
                // Skip mappings that already succeeded on a previous attempt.
                $previous = $state[$mid] ?? null;
                if (is_array($previous) && ($previous['result'] ?? null) === 'sent') {
                    continue;
                }

                $result = $this->pushOne($mapping, $change);
                $state[$mid] = $this->serialiseResult($result);

                if ($result->synced) {
                    $mapping->setEtag($result->etag);
                    if ($result->externalUpdatedAt) {
                        $mapping->setExternalUpdatedAt($result->externalUpdatedAt);
                    }
                    if ($result->externalUrl) {
                        $mapping->setExternalUrl($result->externalUrl);
                    }
                    $mapping->setLastSyncedAt(new \DateTimeImmutable());
                    $mapping->setLastSyncError(null);
                    $totalSent++;
                } elseif ($result->conflict) {
                    $hadConflict = true;
                    $mapping->setLastSyncError('Conflict: ' . ($result->reason ?? 'remote changed'));
                    $totalConflicts++;
                } else {
                    $hadFailure = true;
                    $mapping->setLastSyncError($result->reason);
                    $totalFailed++;
                }
            }

            $row->setPerMappingState($state);
            $row->incrementAttempts();
            $row->setLastError($hadFailure ? 'One or more adapters returned a transient error.' : null);

            if ($hadConflict) {
                $row->setStatus(EntityChangeOutboxStatus::Conflict);
                $row->setProcessedAt(new \DateTimeImmutable());
            } elseif ($hadFailure && $row->getAttemptCount() >= self::MAX_ATTEMPTS) {
                $row->setStatus(EntityChangeOutboxStatus::Failed);
                $row->setProcessedAt(new \DateTimeImmutable());
            } elseif ($hadFailure) {
                $row->setStatus(EntityChangeOutboxStatus::Partial);
                // Exponential backoff: 30s, 2min, 8min, 32min, ...
                $delay = 30 * (2 ** ($row->getAttemptCount() - 1));
                $row->setNextAttemptAt((new \DateTimeImmutable())->modify("+{$delay} seconds"));
            } else {
                $row->setStatus(EntityChangeOutboxStatus::Sent);
                $row->setProcessedAt(new \DateTimeImmutable());
            }
            $this->em->flush();
        }

        $io->success(sprintf(
            'Processed %d row(s) — %d push success, %d conflict, %d failed.',
            \count($rows), $totalSent, $totalConflicts, $totalFailed,
        ));
        return Command::SUCCESS;
    }

    private function pushOne(EntitySync $mapping, EntityChange $change): SyncResult
    {
        $adapter = $this->registry->trySync($mapping->getChannel()->getAdapterCode());
        if ($adapter === null) {
            return SyncResult::failed(sprintf(
                'No SyncableAdapter for code "%s".',
                $mapping->getChannel()->getAdapterCode(),
            ));
        }
        // syncMode = Disabled → skip silently; the mapping is paused.
        if ($mapping->getSyncMode()->value === 'disabled') {
            return SyncResult::synced();
        }
        // Inbound-only mappings ignore outbound pushes by design.
        if ($mapping->getSyncMode()->value === 'inbound') {
            return SyncResult::synced();
        }
        try {
            return $adapter->pushEntity($mapping, $change);
        } catch (\Throwable $e) {
            return SyncResult::retry($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseResult(SyncResult $r): array
    {
        return [
            'result' => $r->synced ? 'sent' : ($r->conflict ? 'conflict' : ($r->retry ? 'retry' : 'failed')),
            'reason' => $r->reason,
            'etag' => $r->etag,
            'externalUpdatedAt' => $r->externalUpdatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
