<?php

declare(strict_types=1);

namespace App\Service\Absence;

use App\Entity\Booking;
use App\Entity\Customer;
use App\Entity\Enum\AssigneePrincipalType;
use App\Entity\Enum\OutboundMessageKind;
use App\Entity\Enum\OutboundMessageStatus;
use App\Entity\OutboundMessage;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\PlanScheduleMessage;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * When a staff member records a limited-availability absence (sickness /
 * child-sickness), the work already scheduled into that window collides with
 * their reduced capacity. This resolver surfaces those collisions — the
 * confirmed appointments they host (Bookings) and the open customer-facing
 * tickets assigned to them (Tasks) — so the UI can ask which ones they can no
 * longer keep, and then applies the decision: cancel the bookings (drafting an
 * invitee notice), and re-plan the schedule (drafting a customer notice for the
 * dropped tickets).
 *
 * Shared by the manual absence form (AbsenceConflictController) and the
 * conversational AI intake (AbsenceIntakeController).
 */
final class AbsenceConflictResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChannelRepository $channels,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * @return array{bookings: list<array<string, mixed>>, customers: list<array<string, mixed>>}
     */
    public function findConflicts(User $user, Workspace $workspace, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return [
            'bookings' => $this->affectedBookings($user, $workspace, $start, $end),
            'customers' => $this->affectedCustomers($user, $workspace, $start, $end),
        ];
    }

    /**
     * Cancel the chosen appointments and re-plan around the chosen tickets,
     * drafting an egress-gated notification for each affected party.
     *
     * @param list<mixed> $bookingIds
     * @param list<mixed> $taskIds
     * @return array{cancelled: list<array<string, mixed>>, drafted: list<array<string, mixed>>}
     */
    public function resolve(User $user, Workspace $workspace, array $bookingIds, array $taskIds): array
    {
        $channel = $this->channels->findEnabledEmailOutbound($workspace)[0] ?? null;

        $cancelled = $this->cancelBookings($user, $workspace, $bookingIds, $channel);
        $drafted = $this->draftCustomerNotices($user, $workspace, $taskIds, $channel);

        $this->em->flush();

        // Freed-up time and dropped tickets → re-plan the staff's schedule.
        $uid = $user->getId();
        $wid = $workspace->getId();
        if (($cancelled !== [] || $drafted !== []) && $uid !== null && $wid !== null) {
            $this->bus->dispatch(new PlanScheduleMessage($uid, $wid));
        }

        return ['cancelled' => $cancelled, 'drafted' => $drafted];
    }

    /**
     * Confirmed appointments the user hosts with a start inside the window.
     *
     * @return list<array<string, mixed>>
     */
    private function affectedBookings(User $user, Workspace $workspace, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        /** @var list<Booking> $bookings */
        $bookings = $this->em->createQueryBuilder()
            ->select('b', 'mt')
            ->from(Booking::class, 'b')
            ->join('b.meetingType', 'mt')
            ->andWhere('b.workspace = :ws')
            ->andWhere('mt.host = :host')
            ->andWhere('b.status = :confirmed')
            ->andWhere('b.startAt >= :winStart AND b.startAt <= :winEnd')
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('host', $user->getId(), UuidType::NAME)
            ->setParameter('confirmed', Booking::STATUS_CONFIRMED)
            ->setParameter('winStart', $start->setTime(0, 0))
            ->setParameter('winEnd', $end->setTime(23, 59, 59))
            ->orderBy('b.startAt', 'ASC')
            ->getQuery()->getResult();

        return array_map(static fn (Booking $b): array => [
            'id' => $b->getId()?->toRfc4122(),
            'inviteeName' => $b->getInviteeName(),
            'inviteeEmail' => $b->getInviteeEmail(),
            'meetingType' => $b->getMeetingType()->getTitle(),
            'startAt' => $b->getStartAt()->format(\DateTimeInterface::ATOM),
        ], $bookings);
    }

    /**
     * Open, customer-facing tickets assigned to the user with a planned slot in
     * the [start, end] window, grouped by customer.
     *
     * @return list<array<string, mixed>>
     */
    private function affectedCustomers(User $user, Workspace $workspace, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        /** @var list<Task> $tasks */
        $tasks = $this->em->createQueryBuilder()
            ->select('t', 'p', 'c')
            ->from(Task::class, 't')
            ->join('t.status', 's')
            ->join('t.project', 'p')
            ->join('p.customer', 'c')
            ->innerJoin('t.assignedPrincipals', 'ap', 'WITH', 'ap.principalType = :ptype AND ap.principalId = :uid')
            ->andWhere('t.workspace = :ws')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('s.isCompleted = false')
            ->andWhere('t.startOn >= :winStart AND t.startOn <= :winEnd')
            ->setParameter('ptype', AssigneePrincipalType::User)
            ->setParameter('uid', $user->getId(), UuidType::NAME)
            ->setParameter('ws', $workspace->getId(), UuidType::NAME)
            ->setParameter('winStart', $start->setTime(0, 0))
            ->setParameter('winEnd', $end->setTime(23, 59, 59))
            ->getQuery()->getResult();

        /** @var array<string, array<string, mixed>> $grouped */
        $grouped = [];
        foreach ($tasks as $task) {
            $customer = $task->getProject()?->getCustomer();
            if ($customer === null) {
                continue;
            }
            $cid = $customer->getId()?->toRfc4122() ?? '';
            $grouped[$cid] ??= [
                'customerId' => $cid,
                'customerName' => $customer->getName(),
                'recipient' => $this->resolveRecipientEmail($customer),
                'tasks' => [],
            ];
            $grouped[$cid]['tasks'][] = [
                'id' => $task->getId()?->toRfc4122(),
                'title' => $task->getTitle(),
                'startOn' => $task->getStartOn()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return array_values($grouped);
    }

    /**
     * @param list<mixed> $bookingIds
     * @return list<array<string, mixed>>
     */
    private function cancelBookings(User $user, Workspace $workspace, array $bookingIds, ?\App\Entity\Channel $channel): array
    {
        $out = [];
        foreach ($bookingIds as $rawId) {
            if (!\is_string($rawId) || !Uuid::isValid($rawId)) {
                continue;
            }
            $booking = $this->em->find(Booking::class, Uuid::fromString($rawId));
            if ($booking === null
                || !$booking->getWorkspace()->getId()?->equals($workspace->getId())
                || $booking->getStatus() !== Booking::STATUS_CONFIRMED
            ) {
                continue;
            }

            $booking->setStatus(Booking::STATUS_CANCELLED);
            $result = [
                'bookingId' => $booking->getId()?->toRfc4122(),
                'invitee' => $booking->getInviteeName(),
                'recipient' => trim($booking->getInviteeEmail()) !== '' ? $booking->getInviteeEmail() : null,
            ];

            $recipient = $result['recipient'];
            if ($channel === null) {
                $out[] = $result + ['notified' => false, 'reason' => 'no_email_channel'];
                continue;
            }
            if ($recipient === null) {
                $out[] = $result + ['notified' => false, 'reason' => 'no_recipient'];
                continue;
            }

            $when = $booking->getStartAt()->format('d.m.Y H:i');
            $message = (new OutboundMessage())
                ->setWorkspace($workspace)
                ->setChannel($channel)
                ->setRecipientRaw($recipient)
                ->setSubject('Ihr Termin muss leider entfallen')
                ->setBody(
                    "Guten Tag {$booking->getInviteeName()},\n\naufgrund einer kurzfristigen Abwesenheit "
                    . "muss Ihr Termin am {$when} ({$booking->getMeetingType()->getTitle()}) leider entfallen. "
                    . "Bitte buchen Sie bei Gelegenheit einen neuen Termin.\n\nWir bitten um Ihr Verständnis.\n\nBeste Grüße",
                )
                ->setKind(OutboundMessageKind::Reply)
                ->setStatus(OutboundMessageStatus::Queued)
                ->setCreatedByUser($user);
            $this->em->persist($message);
            $out[] = $result + ['notified' => true];
        }

        return $out;
    }

    /**
     * @param list<mixed> $taskIds
     * @return list<array<string, mixed>>
     */
    private function draftCustomerNotices(User $user, Workspace $workspace, array $taskIds, ?\App\Entity\Channel $channel): array
    {
        // Group the (validated) tasks by customer.
        /** @var array<string, array{customer: Customer, titles: list<string>}> $byCustomer */
        $byCustomer = [];
        foreach ($taskIds as $rawId) {
            if (!\is_string($rawId) || !Uuid::isValid($rawId)) {
                continue;
            }
            $task = $this->em->find(Task::class, Uuid::fromString($rawId));
            $customer = $task?->getProject()?->getCustomer();
            if ($task === null || $customer === null || !$task->getWorkspace()->getId()?->equals($workspace->getId())) {
                continue;
            }
            $cid = $customer->getId()?->toRfc4122() ?? '';
            $byCustomer[$cid] ??= ['customer' => $customer, 'titles' => []];
            $byCustomer[$cid]['titles'][] = $task->getTitle();
        }

        $drafted = [];
        foreach ($byCustomer as $entry) {
            $customer = $entry['customer'];
            $recipient = $this->resolveRecipientEmail($customer);
            $result = ['customerId' => $customer->getId()?->toRfc4122(), 'customerName' => $customer->getName(), 'recipient' => $recipient];
            if ($channel === null) {
                $drafted[] = $result + ['created' => false, 'reason' => 'no_email_channel'];
                continue;
            }
            if ($recipient === null) {
                $drafted[] = $result + ['created' => false, 'reason' => 'no_recipient'];
                continue;
            }

            $list = implode('', array_map(static fn (string $t): string => "\n· " . $t, $entry['titles']));
            $message = (new OutboundMessage())
                ->setWorkspace($workspace)
                ->setChannel($channel)
                ->setRecipientRaw($recipient)
                ->setSubject('Kurzfristige Verzögerung bei Ihren offenen Aufgaben')
                ->setBody(
                    "Guten Tag,\n\naufgrund einer kurzfristigen Abwesenheit verzögert sich voraussichtlich die "
                    . "Bearbeitung der folgenden Aufgaben:{$list}\n\nWir melden uns, sobald es weitergeht, und "
                    . "bitten um Ihr Verständnis.\n\nBeste Grüße",
                )
                ->setKind(OutboundMessageKind::Reply)
                ->setStatus(OutboundMessageStatus::Queued)
                ->setCreatedByUser($user);
            $this->em->persist($message);
            $drafted[] = $result + ['created' => true];
        }

        return $drafted;
    }

    public function resolveRecipientEmail(Customer $customer): ?string
    {
        $email = trim((string) $customer->getEmail());
        if ($email !== '') {
            return $email;
        }
        foreach ($customer->getContacts() as $contact) {
            $contactEmail = trim((string) $contact->getEmail());
            if ($contactEmail !== '') {
                return $contactEmail;
            }
        }

        return null;
    }
}
