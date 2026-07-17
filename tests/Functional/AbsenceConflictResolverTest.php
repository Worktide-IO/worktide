<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\MeetingType;
use App\Entity\User;
use App\Entity\Workspace;
use App\Service\Absence\AbsenceConflictResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The conflict resolver surfaces the appointments a staff member hosts inside an
 * absence window and, on resolve, cancels the chosen ones. (Notification drafts
 * are best-effort and skipped here — no outbound channel is seeded.)
 */
final class AbsenceConflictResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AbsenceConflictResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(AbsenceConflictResolver::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testFindsAndCancelsHostedBookingInWindow(): void
    {
        [$ws, $host, $booking] = $this->seed();
        $start = new \DateTimeImmutable('+2 days 00:00');
        $end = new \DateTimeImmutable('+2 days 23:59');

        $conflicts = $this->resolver->findConflicts($host, $ws, $start, $end);
        self::assertCount(1, $conflicts['bookings']);
        self::assertSame($booking->getId()?->toRfc4122(), $conflicts['bookings'][0]['id']);
        self::assertArrayNotHasKey('type', $conflicts['bookings'][0]);

        $result = $this->resolver->resolve($host, $ws, [$booking->getId()?->toRfc4122()], []);
        self::assertCount(1, $result['cancelled']);

        $this->em->refresh($booking);
        self::assertTrue($booking->isCancelled(), 'the chosen appointment is cancelled');
    }

    /** @return array{0: Workspace, 1: User, 2: Booking} */
    private function seed(): array
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        $host = (new User())->setEmail('host-' . substr(Uuid::v7()->toRfc4122(), 0, 8) . '@example.test')
            ->setFirstName('H')->setLastName('Ost')->setRoles([]);
        $host->setPassword('x');
        $this->em->persist($host);

        $type = (new MeetingType())
            ->setSlug('t-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setTitle('Beratung')->setDurationMinutes(30)->setEnabled(true)
            ->setTimezone('Europe/Berlin')->setMinNoticeMinutes(0)->setMaxAdvanceDays(30)
            ->setAvailability([])->setHost($host);
        $type->setWorkspace($ws);
        $this->em->persist($type);

        $at = new \DateTimeImmutable('+2 days 10:00');
        $booking = (new Booking())
            ->setMeetingType($type)
            ->setStartAt($at)
            ->setEndAt($at->modify('+30 minutes'))
            ->setInviteeName('Klaus Kunde')
            ->setInviteeEmail('klaus@example.test')
            ->setCancelToken(substr(Uuid::v7()->toRfc4122(), 0, 16))
            ->setStatus(Booking::STATUS_CONFIRMED);
        $this->em->persist($booking);
        $this->em->flush();

        return [$ws, $host, $booking];
    }
}
