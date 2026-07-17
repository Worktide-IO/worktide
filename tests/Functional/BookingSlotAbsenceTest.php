<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\MeetingType;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceAbsence;
use App\Service\Booking\BookingSlotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The booking slot engine blanks out days the host is away: a personal
 * {@see Absence} (vacation) and a workspace-wide {@see WorkspaceAbsence}
 * (company closure) both remove that day's slots, while adjacent days stay open.
 */
final class BookingSlotAbsenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BookingSlotService $slots;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->slots = self::getContainer()->get(BookingSlotService::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function testPersonalAbsenceBlocksThatDayOnly(): void
    {
        [$type, $host, $ws] = $this->seedType();
        $dayA = new \DateTimeImmutable('+6 days 00:00', new \DateTimeZone('UTC'));
        $dayB = $dayA->modify('+1 day');

        self::assertNotEmpty($this->slotsOn($type, $dayA), 'baseline: day A has slots');
        self::assertNotEmpty($this->slotsOn($type, $dayB), 'baseline: day B has slots');

        $this->persistAbsence($host, $ws, $dayA);

        self::assertSame([], $this->slotsOn($type, $dayA), 'host absence blanks day A');
        self::assertNotEmpty($this->slotsOn($type, $dayB), 'day B unaffected');
    }

    public function testLimitedAvailabilityAbsenceKeepsSlotsOpen(): void
    {
        [$type, $host, $ws] = $this->seedType();
        $day = new \DateTimeImmutable('+6 days 00:00', new \DateTimeZone('UTC'));

        self::assertNotEmpty($this->slotsOn($type, $day), 'baseline: day has slots');

        $this->persistAbsence($host, $ws, $day, availabilityPercent: 50);

        self::assertNotEmpty(
            $this->slotsOn($type, $day),
            'a limited-availability absence (50 %) must NOT blank booking slots',
        );
    }

    public function testWorkspaceClosureBlocksThatDay(): void
    {
        [$type, , $ws] = $this->seedType();
        $day = new \DateTimeImmutable('+6 days 00:00', new \DateTimeZone('UTC'));

        self::assertNotEmpty($this->slotsOn($type, $day), 'baseline: day has slots');

        $closure = (new WorkspaceAbsence())
            ->setName('Betriebsferien')
            ->setStartsOn(new \DateTimeImmutable($day->format('Y-m-d') . ' 12:00', new \DateTimeZone('Europe/Berlin')))
            ->setEndsOn(new \DateTimeImmutable($day->format('Y-m-d') . ' 12:00', new \DateTimeZone('Europe/Berlin')));
        $closure->setWorkspace($ws);
        $this->em->persist($closure);
        $this->em->flush();

        self::assertSame([], $this->slotsOn($type, $day), 'workspace closure blanks the day');
    }

    /** @return array{0: MeetingType, 1: User, 2: Workspace} */
    private function seedType(): array
    {
        $ws = (new Workspace())->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        $host = (new User())->setEmail('host-' . substr(Uuid::v7()->toRfc4122(), 0, 8) . '@example.test')
            ->setFirstName('H')->setLastName('Ost')->setRoles([]);
        $host->setPassword('x');
        $this->em->persist($host);

        $availability = [];
        for ($weekday = 1; $weekday <= 7; ++$weekday) {
            $availability[] = ['weekday' => $weekday, 'start' => '08:00', 'end' => '17:00'];
        }
        $type = (new MeetingType())
            ->setSlug('t-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setTitle('Beratung')
            ->setDurationMinutes(30)
            ->setEnabled(true)
            ->setTimezone('Europe/Berlin')
            ->setMinNoticeMinutes(0)
            ->setMaxAdvanceDays(30)
            ->setAvailability($availability)
            ->setHost($host);
        $type->setWorkspace($ws);
        $this->em->persist($type);
        $this->em->flush();

        return [$type, $host, $ws];
    }

    private function persistAbsence(User $host, Workspace $ws, \DateTimeImmutable $day, int $availabilityPercent = 0): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        $absence = (new Absence())
            ->setUser($host)
            ->setStartsOn(new \DateTimeImmutable($day->format('Y-m-d') . ' 12:00', $berlin))
            ->setEndsOn(new \DateTimeImmutable($day->format('Y-m-d') . ' 12:00', $berlin))
            ->setType($availabilityPercent > 0 ? 'sick' : 'vacation')
            ->setAvailabilityPercent($availabilityPercent);
        $absence->setWorkspace($ws);
        $this->em->persist($absence);
        $this->em->flush();
    }

    /** @return list<string> ISO instants of that UTC day's slots */
    private function slotsOn(MeetingType $type, \DateTimeImmutable $dayStartUtc): array
    {
        return array_map(
            static fn (\DateTimeImmutable $s) => $s->format(\DateTimeInterface::ATOM),
            $this->slots->availableSlots($type, $dayStartUtc, $dayStartUtc->modify('+1 day')),
        );
    }
}
