<?php

declare(strict_types=1);

namespace App\Tests\Service\Crm;

use App\Entity\CustomerAgreement;
use App\Entity\CustomerAgreementRevision;
use App\Entity\Enum\AgreementStatus;
use App\Repository\AgreementTypeRepository;
use App\Repository\CustomerAgreementRepository;
use App\Service\Crm\AgreementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for {@see AgreementService::recompute()} — the head state
 * machine — built from in-memory revisions (no DB needed; the EM/repos are
 * stubs the method never touches).
 */
final class AgreementServiceTest extends TestCase
{
    private AgreementService $service;

    protected function setUp(): void
    {
        $this->service = new AgreementService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AgreementTypeRepository::class),
            $this->createStub(CustomerAgreementRepository::class),
        );
    }

    private function rev(int $versionNo, AgreementStatus $status, ?string $validUntil = null): CustomerAgreementRevision
    {
        $rev = (new CustomerAgreementRevision())
            ->setVersionNo($versionNo)
            ->setStatus($status);
        if ($validUntil !== null) {
            $rev->setValidUntil(new \DateTimeImmutable($validUntil));
        }

        return $rev;
    }

    private function headWith(CustomerAgreementRevision ...$revs): CustomerAgreement
    {
        $head = new CustomerAgreement();
        foreach ($revs as $r) {
            $head->getRevisions()->add($r);
        }

        return $head;
    }

    public function testEmptyHeadIsNone(): void
    {
        $head = $this->headWith();
        $this->service->recompute($head, new \DateTimeImmutable('2026-06-29'));

        self::assertSame(AgreementStatus::None, $head->getStatus());
        self::assertNull($head->getCurrentRevision());
        self::assertNull($head->getPendingRevision());
    }

    public function testSignedAndValidIsSigned(): void
    {
        $signed = $this->rev(1, AgreementStatus::Signed, '2027-12-31');
        $head = $this->headWith($signed);
        $this->service->recompute($head, new \DateTimeImmutable('2026-06-29'));

        self::assertSame(AgreementStatus::Signed, $head->getStatus());
        self::assertSame($signed, $head->getCurrentRevision());
        self::assertEquals(new \DateTimeImmutable('2027-12-31'), $head->getValidUntil());
        self::assertTrue($head->getIsSigned());
    }

    public function testSignedButLapsedIsExpired(): void
    {
        $head = $this->headWith($this->rev(1, AgreementStatus::Signed, '2025-01-01'));
        $this->service->recompute($head, new \DateTimeImmutable('2026-06-29'));

        self::assertSame(AgreementStatus::Expired, $head->getStatus());
    }

    public function testInNegotiationOnlyIsPending(): void
    {
        $pending = $this->rev(1, AgreementStatus::InNegotiation);
        $head = $this->headWith($pending);
        $this->service->recompute($head, new \DateTimeImmutable('2026-06-29'));

        self::assertSame(AgreementStatus::InNegotiation, $head->getStatus());
        self::assertNull($head->getCurrentRevision());
        self::assertSame($pending, $head->getPendingRevision());
    }

    public function testSignedWithNewerNegotiationKeepsSignedButTracksPending(): void
    {
        $signed = $this->rev(1, AgreementStatus::Signed, '2027-12-31');
        $pending = $this->rev(2, AgreementStatus::InNegotiation);
        $head = $this->headWith($signed, $pending);
        $this->service->recompute($head, new \DateTimeImmutable('2026-06-29'));

        self::assertSame(AgreementStatus::Signed, $head->getStatus());
        self::assertSame($signed, $head->getCurrentRevision());
        self::assertSame($pending, $head->getPendingRevision());
    }

    public function testNewerSignedSupersedesOlder(): void
    {
        $old = $this->rev(1, AgreementStatus::Signed, '2026-12-31');
        $new = $this->rev(2, AgreementStatus::Signed, '2028-12-31');
        $head = $this->headWith($old, $new);
        $this->service->recompute($head, new \DateTimeImmutable('2026-06-29'));

        self::assertSame($new, $head->getCurrentRevision());
        self::assertSame(AgreementStatus::Superseded, $old->getStatus());
        self::assertSame(AgreementStatus::Signed, $head->getStatus());
    }

    public function testTerminatedWins(): void
    {
        $signed = $this->rev(1, AgreementStatus::Signed, '2027-12-31');
        $terminated = $this->rev(2, AgreementStatus::Terminated);
        $head = $this->headWith($signed, $terminated);
        $this->service->recompute($head, new \DateTimeImmutable('2026-06-29'));

        self::assertSame(AgreementStatus::Terminated, $head->getStatus());
    }
}
