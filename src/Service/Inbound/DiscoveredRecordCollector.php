<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Channels\EntitySnapshot;
use App\Channels\ExternalParticipant;
use App\Entity\Channel;
use App\Entity\DiscoveredExternalRecord;
use App\Repository\DiscoveredExternalRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Parks an unmapped external snapshot as a {@see DiscoveredExternalRecord} when
 * it involves a workspace person — the capture side of the discovered-import
 * path (C.7.6). Called by {@see \App\Channels\EntityApplier} in its
 * no-mapping branch.
 *
 * Relevance gate: {@see InboundImportFilter}. A snapshot whose participants
 * (assignee/watcher) map to nobody in the channel's workspace is dropped — this
 * is what stops a connection from sucking in an entire foreign project.
 *
 * Idempotent: one row per (channel, externalId), upserted on repeated
 * webhooks/pulls. A settled record (Imported/Linked/Dismissed) keeps its state;
 * only its preview fields refresh. Persists via the EM but does NOT flush — the
 * caller owns the transaction.
 */
final class DiscoveredRecordCollector
{
    private const TITLE_MAX = 250;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundImportFilter $filter,
        private readonly DiscoveredExternalRecordRepository $records,
    ) {}

    /**
     * Park an AMBIGUOUS master-data match as a conflict record for operator
     * resolution — the Lexoffice/awork adapters call this when an external
     * customer matched more than one local Customer, so the sync must not guess.
     * Unlike {@see capture()} there is no participant-relevance gate (every
     * ambiguous match is actionable). Idempotent per (channel, externalId);
     * never resurrects a settled record. Persists without flushing — the
     * adapter owns the transaction.
     *
     * @param array<string, mixed>                    $fields     normalized create-from payload
     * @param list<array{id: string, label: string}>  $candidates the local matches to choose between
     */
    public function captureConflict(
        Channel $channel,
        string $entityType,
        string $externalId,
        string $title,
        ?string $externalUrl,
        array $fields,
        array $candidates,
    ): void {
        $record = $this->records->findOneByChannelExternal($channel, $externalId);
        if ($record !== null && $record->getState() !== \App\Entity\Enum\DiscoveredRecordState::Pending) {
            return; // already imported/linked/dismissed — leave it settled
        }
        $record ??= (new DiscoveredExternalRecord())
            ->setChannel($channel)
            ->setWorkspace($channel->getWorkspace())
            ->setEntityType($entityType)
            ->setExternalId($externalId);

        $record
            ->setEntityType($entityType)
            ->setTitle(mb_substr($title !== '' ? $title : $externalId, 0, self::TITLE_MAX))
            ->setExternalUrl($externalUrl)
            ->setFields($fields)
            ->setCandidates($candidates);

        $this->em->persist($record);
    }

    public function capture(Channel $channel, EntitySnapshot $snapshot): void
    {
        if (!$this->filter->isRelevant($channel, $snapshot->participants)) {
            return;
        }

        $record = $this->records->findOneByChannelExternal($channel, $snapshot->externalId)
            ?? (new DiscoveredExternalRecord())
                ->setChannel($channel)
                ->setWorkspace($channel->getWorkspace())
                ->setEntityType($snapshot->entityType)
                ->setExternalId($snapshot->externalId);

        // Refresh the preview regardless of state; state itself is left to the
        // action endpoints (don't resurrect a dismissed/imported record).
        $record
            ->setTitle($this->title($snapshot))
            ->setExternalUrl($snapshot->externalUrl)
            ->setFields($snapshot->fields)
            ->setParticipants($this->serializeParticipants($snapshot->participants));

        $this->em->persist($record);
    }

    private function title(EntitySnapshot $snapshot): string
    {
        $title = (string) ($snapshot->fields['title'] ?? '');
        if ($title === '') {
            $title = sprintf('%s %s', $snapshot->entityType, $snapshot->externalId);
        }

        return mb_substr($title, 0, self::TITLE_MAX);
    }

    /**
     * @param list<ExternalParticipant> $participants
     *
     * @return list<array<string, mixed>>
     */
    private function serializeParticipants(array $participants): array
    {
        return array_map(
            static fn (ExternalParticipant $p): array => [
                'externalUserId' => $p->externalUserId,
                'email' => $p->email,
                'role' => $p->role,
            ],
            $participants,
        );
    }
}
