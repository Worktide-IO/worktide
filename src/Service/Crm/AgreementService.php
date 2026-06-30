<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\Customer;
use App\Entity\CustomerAgreement;
use App\Entity\CustomerAgreementRevision;
use App\Entity\Enum\AgreementStatus;
use App\Entity\File;
use App\Entity\User;
use App\Repository\AgreementTypeRepository;
use App\Repository\CustomerAgreementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single entry point for recording and recomputing customer agreements.
 *
 * Clients address an agreement by (customer, type-slug). {@see self::set()}
 * creates the head on first use, appends an immutable
 * {@see CustomerAgreementRevision}, and {@see self::recompute()} derives the
 * head's effective state from the full revision set:
 *
 *   - currentRevision  = newest Signed version (older signed → Superseded)
 *   - pendingRevision  = newest still-open version (Draft/InNegotiation) that
 *                        is newer than the current one ("in Abstimmung")
 *   - status/signedOn/validUntil are denormalised onto the head so overview
 *     queries don't join.
 *
 * Keeping all head bookkeeping here means the convenience endpoint, direct
 * service callers and the expiry command can't drift apart.
 */
final class AgreementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgreementTypeRepository $types,
        private readonly CustomerAgreementRepository $agreements,
    ) {}

    /**
     * Upsert the agreement for (customer, typeSlug) by appending a new revision
     * in the given state, then recomputing the head. Returns the head.
     *
     * @throws \InvalidArgumentException when the slug is unknown for the workspace
     */
    public function set(
        Customer $customer,
        string $typeSlug,
        AgreementStatus $status,
        ?\DateTimeImmutable $signedOn = null,
        ?\DateTimeImmutable $validUntil = null,
        ?string $reference = null,
        ?File $file = null,
        ?string $notes = null,
        ?User $actor = null,
    ): CustomerAgreement {
        $type = $this->types->findOneBySlug($customer->getWorkspace(), $typeSlug);
        if ($type === null) {
            throw new \InvalidArgumentException(\sprintf('Unknown agreement type "%s" for this workspace.', $typeSlug));
        }

        $head = $this->agreements->findOneForType($customer, $type);
        if ($head === null) {
            $head = (new CustomerAgreement())->setCustomer($customer)->setType($type);
            $this->em->persist($head);
        }

        $revision = (new CustomerAgreementRevision())
            ->setAgreement($head)
            ->setVersionNo($this->nextVersionNo($head))
            ->setStatus($status)
            ->setSignedOn($signedOn)
            ->setValidUntil($validUntil)
            ->setReference($reference)
            ->setFile($file)
            ->setNotes($notes);
        if ($actor !== null) {
            $revision->setCreatedByUser($actor);
        }
        $head->addRevision($revision);
        $this->em->persist($revision);

        $this->recompute($head);
        $this->em->flush();

        return $head;
    }

    /**
     * Derive the head's effective state from its revisions. Idempotent — safe
     * to call after any revision change or from the expiry command.
     */
    public function recompute(CustomerAgreement $head, ?\DateTimeImmutable $asOf = null): void
    {
        $asOf ??= new \DateTimeImmutable('today');

        $revisions = $head->getRevisions()->toArray();
        usort($revisions, static fn (CustomerAgreementRevision $a, CustomerAgreementRevision $b) => $a->getVersionNo() <=> $b->getVersionNo());
        $latest = $revisions === [] ? null : $revisions[\count($revisions) - 1];

        // current = newest Signed version
        $current = null;
        foreach ($revisions as $r) {
            if ($r->getStatus() === AgreementStatus::Signed) {
                $current = $r;
            }
        }
        // older signed versions are retired
        foreach ($revisions as $r) {
            if ($r !== $current && $r->getStatus() === AgreementStatus::Signed) {
                $r->setStatus(AgreementStatus::Superseded);
            }
        }
        // pending = newest open version newer than the current one
        $pending = null;
        foreach ($revisions as $r) {
            if ($r->getStatus()->isPending() && ($current === null || $r->getVersionNo() > $current->getVersionNo())) {
                $pending = $r;
            }
        }

        $head->setCurrentRevision($current);
        $head->setPendingRevision($pending);
        $head->setSignedOn($current?->getSignedOn());
        $head->setValidUntil($current?->getValidUntil());

        if ($latest !== null && $latest->getStatus() === AgreementStatus::Terminated) {
            $head->setStatus(AgreementStatus::Terminated);
        } elseif ($current !== null) {
            $expired = $current->getValidUntil() !== null && $current->getValidUntil() < $asOf;
            $head->setStatus($expired ? AgreementStatus::Expired : AgreementStatus::Signed);
        } elseif ($pending !== null) {
            $head->setStatus(AgreementStatus::InNegotiation);
        } elseif ($latest !== null) {
            $head->setStatus($latest->getStatus());
        } else {
            $head->setStatus(AgreementStatus::None);
        }
    }

    private function nextVersionNo(CustomerAgreement $head): int
    {
        $max = 0;
        foreach ($head->getRevisions() as $r) {
            $max = max($max, $r->getVersionNo());
        }

        return $max + 1;
    }
}
