<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Booking;
use App\Entity\MeetingType;
use App\Entity\Workspace;
use App\Repository\BookingRepository;
use App\Service\Booking\BookingSlotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Public appointment reschedule (`/v1/book/reschedule/{token}`): the invitee
 * moves a booking in place with the cancel-token as the only credential. Covers
 * the happy path (moved + rescheduledCount bumped), the cancelled guard, unknown
 * tokens, and the overlap re-check that must ignore the booking being moved.
 */
final class BookingRescheduleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // The controller wraps its move in a nested transaction; DBAL 4 uses
        // savepoints for that automatically, so the outer test tx is safe.
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    public function testRescheduleMovesBookingAndBumpsCount(): void
    {
        [$type, $slots] = $this->seedTypeWithSlots();
        // Occupy one slot; reschedule it onto a different free slot.
        $original = $slots[3];
        $target = $slots[10];
        $booking = $this->seedBooking($type, $original);
        $token = $booking->getCancelToken();

        // Info endpoint exposes the slug + current start.
        $this->client->request('GET', '/v1/book/reschedule/' . $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $info = $this->json();
        self::assertSame($type->getSlug(), $info['slug']);
        self::assertFalse($info['cancelled']);

        // Move it.
        $this->post('/v1/book/reschedule/' . $token, ['start' => $target->format(\DateTimeInterface::ATOM)]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->json()['success']);

        $this->em->clear();
        $moved = self::getContainer()->get(BookingRepository::class)->findOneByCancelToken($token);
        self::assertNotNull($moved);
        self::assertSame($target->getTimestamp(), $moved->getStartAt()->getTimestamp(), 'startAt moved to the target slot');
        self::assertSame(1, $moved->getRescheduledCount(), 'reschedule counter bumped');
        self::assertFalse($moved->isCancelled(), 'still confirmed');
    }

    public function testCancelledBookingCannotBeRescheduled(): void
    {
        [$type, $slots] = $this->seedTypeWithSlots();
        $booking = $this->seedBooking($type, $slots[3]);
        $booking->setStatus(Booking::STATUS_CANCELLED);
        $this->em->flush();

        $this->post('/v1/book/reschedule/' . $booking->getCancelToken(), ['start' => $slots[10]->format(\DateTimeInterface::ATOM)]);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownTokenIs404(): void
    {
        $this->client->request('GET', '/v1/book/reschedule/deadbeefdeadbeef');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->post('/v1/book/reschedule/deadbeefdeadbeef', ['start' => (new \DateTimeImmutable('+2 days'))->format(\DateTimeInterface::ATOM)]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testOverlapCheckExcludesTheBookingBeingMoved(): void
    {
        [$type, $slots] = $this->seedTypeWithSlots();
        $booking = $this->seedBooking($type, $slots[3]);
        $repo = self::getContainer()->get(BookingRepository::class);

        // Its own slot reads as taken in general…
        self::assertTrue($repo->hasConfirmedOverlap($type, $booking->getStartAt(), $booking->getEndAt()));
        // …but not when the booking itself is excluded (so it can stay put / shift).
        self::assertFalse($repo->hasConfirmedOverlap($type, $booking->getStartAt(), $booking->getEndAt(), $booking));
    }

    /**
     * A fully-open meeting type + a batch of real bookable slot instants.
     *
     * @return array{0: MeetingType, 1: list<\DateTimeImmutable>}
     */
    private function seedTypeWithSlots(): array
    {
        $ws = (new Workspace())
            ->setName('WS')
            ->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')->setSettings([]);
        $this->em->persist($ws);

        $availability = [];
        for ($weekday = 1; $weekday <= 7; ++$weekday) {
            $availability[] = ['weekday' => $weekday, 'start' => '00:00', 'end' => '23:59'];
        }
        $type = (new MeetingType())
            ->setSlug('resched-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setTitle('Reschedule Test')
            ->setDurationMinutes(30)
            ->setEnabled(true)
            ->setTimezone('Europe/Berlin')
            ->setMinNoticeMinutes(0)
            ->setMaxAdvanceDays(30)
            ->setAvailability($availability);
        $type->setWorkspace($ws);
        $this->em->persist($type);
        $this->em->flush();

        // Ask the real slot service for offerable instants a couple of days out.
        $from = new \DateTimeImmutable('+2 days 00:00', new \DateTimeZone('UTC'));
        $to = $from->modify('+1 day');
        $slots = self::getContainer()->get(BookingSlotService::class)->availableSlots($type, $from, $to);
        self::assertGreaterThan(12, \count($slots), 'seed availability should yield plenty of slots');

        return [$type, $slots];
    }

    private function seedBooking(MeetingType $type, \DateTimeImmutable $startUtc): Booking
    {
        $booking = (new Booking())
            ->setMeetingType($type)
            ->setStartAt($startUtc)
            ->setEndAt($startUtc->modify('+' . $type->getDurationMinutes() . ' minutes'))
            ->setInviteeName('Test Invitee')
            ->setInviteeEmail('invitee@example.test')
            ->setCancelToken(bin2hex(random_bytes(24)));
        $this->em->persist($booking);
        $this->em->flush();

        return $booking;
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body): void
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }
}
