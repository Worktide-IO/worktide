<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Booking;
use App\Entity\MeetingType;
use App\Repository\BookingRepository;
use App\Repository\MeetingTypeRepository;
use App\Service\Booking\BookingMailer;
use App\Service\Booking\BookingSlotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated appointment booking (Calendly-style). The slug in the
 * path is the only credential (security.yaml `^/v1/book/` PUBLIC_ACCESS). Staff
 * manage the MeetingType definition via the API Platform resource; this
 * controller is the anonymous booking surface, mirroring PublicFormController.
 *
 *   GET  /v1/book/{slug}          → public-safe meeting type
 *   GET  /v1/book/{slug}/slots    → available slot starts (UTC) for a date range
 *   POST /v1/book/{slug}          → create a booking (honeypot + per-IP limiter)
 *   GET  /v1/book/cancel/{token}  → booking summary for the cancel page
 *   POST /v1/book/cancel/{token}  → cancel the booking
 *
 * Unknown/disabled/deleted slugs and tokens all 404 identically so nothing can
 * be probed. Workspace is resolved from the slug via a custom repository query
 * (bypassing the member-only WorkspaceScopeExtension) and stamped onto the
 * Booking; createdByUser stays null on this anonymous path.
 */
final class PublicBookingController
{
    private const HONEYPOT_FIELD = '_hp';
    private const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly MeetingTypeRepository $meetingTypes,
        private readonly BookingRepository $bookings,
        private readonly BookingSlotService $slots,
        private readonly BookingMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactory $publicFormSubmitLimiter,
    ) {}

    #[Route(path: '/v1/book/{slug}', name: 'api_public_booking_type', requirements: ['slug' => '[a-z0-9-]{1,60}'], methods: ['GET'])]
    public function type(string $slug): JsonResponse
    {
        $type = $this->requireType($slug);

        return new JsonResponse($this->typeDto($type));
    }

    #[Route(path: '/v1/book/{slug}/slots', name: 'api_public_booking_slots', requirements: ['slug' => '[a-z0-9-]{1,60}'], methods: ['GET'])]
    public function slots(string $slug, Request $request): JsonResponse
    {
        $type = $this->requireType($slug);
        $utc = new \DateTimeZone('UTC');

        try {
            $fromDate = new \DateTimeImmutable(($request->query->getString('from') ?: 'today') . ' 00:00:00', $utc);
            $toRaw = $request->query->getString('to');
            $toDate = $toRaw !== ''
                ? (new \DateTimeImmutable($toRaw . ' 00:00:00', $utc))->modify('+1 day')
                : $fromDate->modify('+14 days');
        } catch (\Exception) {
            throw new BadRequestHttpException('Invalid from/to date.');
        }
        // Cap the window so a hand-crafted request can't scan a huge range.
        $cap = $fromDate->modify('+' . self::MAX_RANGE_DAYS . ' days');
        if ($toDate > $cap) {
            $toDate = $cap;
        }

        $slots = array_map(
            static fn (\DateTimeImmutable $s) => $s->setTimezone($utc)->format(\DateTimeInterface::ATOM),
            $this->slots->availableSlots($type, $fromDate, $toDate),
        );

        return new JsonResponse([
            'slots' => $slots,
            'durationMinutes' => $type->getDurationMinutes(),
            'timezone' => $type->getTimezone(),
        ]);
    }

    #[Route(path: '/v1/book/{slug}', name: 'api_public_booking_create', requirements: ['slug' => '[a-z0-9-]{1,60}'], methods: ['POST'])]
    public function create(string $slug, Request $request): JsonResponse
    {
        $type = $this->requireType($slug);

        // Per-IP throttle before any work (reuses the public-form submit limiter).
        $limiter = $this->publicFormSubmitLimiter->create($request->getClientIp() ?? 'unknown');
        $reservation = $limiter->consume(1);
        if (!$reservation->isAccepted()) {
            $retryAfter = max(1, (int) ceil($reservation->getRetryAfter()->getTimestamp() - time()));
            throw new TooManyRequestsHttpException($retryAfter, sprintf('Too many requests — retry in %ds.', $retryAfter));
        }

        $body = $this->decode($request);

        // Honeypot: return the normal success shape so a bot can't tell.
        if (($body[self::HONEYPOT_FIELD] ?? '') !== '') {
            return new JsonResponse(['success' => true], 201);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $startRaw = (string) ($body['start'] ?? '');
        if ($name === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL) || $startRaw === '') {
            throw new BadRequestHttpException('name, a valid email and start are required.');
        }
        try {
            $start = (new \DateTimeImmutable($startRaw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new BadRequestHttpException('Invalid start.');
        }
        $end = $start->modify('+' . $type->getDurationMinutes() . ' minutes');

        // Re-validate the slot server-side (grid + notice window + free), then
        // guard the double-booking race with a transaction + overlap re-check.
        if (!$this->slots->isBookable($type, $start)) {
            throw new ConflictHttpException('This slot is no longer available.');
        }

        $booking = $this->em->wrapInTransaction(function () use ($type, $start, $end, $name, $email, $body): Booking {
            if ($this->bookings->hasConfirmedOverlap($type, $start, $end)) {
                throw new ConflictHttpException('This slot was just taken.');
            }
            $booking = (new Booking())
                ->setMeetingType($type)
                ->setStartAt($start)
                ->setEndAt($end)
                ->setInviteeName(mb_substr($name, 0, 200))
                ->setInviteeEmail(mb_substr($email, 0, 255))
                ->setInviteeTimezone($this->cleanTz($body['timezone'] ?? null))
                ->setNotes(\is_string($body['notes'] ?? null) && $body['notes'] !== '' ? $body['notes'] : null)
                ->setCancelToken(bin2hex(random_bytes(24)));
            $this->em->persist($booking);

            return $booking;
        });

        $this->mailer->sendConfirmation($booking);

        return new JsonResponse([
            'success' => true,
            'cancelToken' => $booking->getCancelToken(),
            'start' => $start->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route(path: '/v1/book/cancel/{token}', name: 'api_public_booking_cancel_info', requirements: ['token' => '[a-zA-Z0-9]{1,64}'], methods: ['GET'])]
    public function cancelInfo(string $token): JsonResponse
    {
        $booking = $this->bookings->findOneByCancelToken($token);
        if ($booking === null) {
            throw new NotFoundHttpException();
        }
        $type = $booking->getMeetingType();
        $tz = new \DateTimeZone($booking->getInviteeTimezone() ?: $type->getTimezone());

        return new JsonResponse([
            'title' => $type->getTitle(),
            'start' => $booking->getStartAt()->setTimezone($tz)->format(\DateTimeInterface::ATOM),
            'timezone' => $tz->getName(),
            'cancelled' => $booking->isCancelled(),
        ]);
    }

    #[Route(path: '/v1/book/cancel/{token}', name: 'api_public_booking_cancel', requirements: ['token' => '[a-zA-Z0-9]{1,64}'], methods: ['POST'])]
    public function cancel(string $token): JsonResponse
    {
        $booking = $this->bookings->findOneByCancelToken($token);
        if ($booking === null) {
            throw new NotFoundHttpException();
        }
        if (!$booking->isCancelled()) {
            $booking->setStatus(Booking::STATUS_CANCELLED);
            $this->em->flush();
            $this->mailer->sendCancellation($booking);
        }

        return new JsonResponse(['success' => true, 'cancelled' => true]);
    }

    #[Route(path: '/v1/book/reschedule/{token}', name: 'api_public_booking_reschedule_info', requirements: ['token' => '[a-zA-Z0-9]{1,64}'], methods: ['GET'])]
    public function rescheduleInfo(string $token): JsonResponse
    {
        $booking = $this->bookings->findOneByCancelToken($token);
        if ($booking === null) {
            throw new NotFoundHttpException();
        }
        $type = $booking->getMeetingType();
        $tz = new \DateTimeZone($booking->getInviteeTimezone() ?: $type->getTimezone());

        return new JsonResponse([
            'slug' => $type->getSlug(),
            'title' => $type->getTitle(),
            'start' => $booking->getStartAt()->setTimezone($tz)->format(\DateTimeInterface::ATOM),
            'timezone' => $tz->getName(),
            'durationMinutes' => $type->getDurationMinutes(),
            'cancelled' => $booking->isCancelled(),
        ]);
    }

    #[Route(path: '/v1/book/reschedule/{token}', name: 'api_public_booking_reschedule', requirements: ['token' => '[a-zA-Z0-9]{1,64}'], methods: ['POST'])]
    public function reschedule(string $token, Request $request): JsonResponse
    {
        // Per-IP throttle first (reuses the public-form submit limiter).
        $limiter = $this->publicFormSubmitLimiter->create($request->getClientIp() ?? 'unknown');
        $reservation = $limiter->consume(1);
        if (!$reservation->isAccepted()) {
            $retryAfter = max(1, (int) ceil($reservation->getRetryAfter()->getTimestamp() - time()));
            throw new TooManyRequestsHttpException($retryAfter, sprintf('Too many requests — retry in %ds.', $retryAfter));
        }

        $booking = $this->bookings->findOneByCancelToken($token);
        if ($booking === null) {
            throw new NotFoundHttpException();
        }
        if ($booking->isCancelled()) {
            throw new ConflictHttpException('This booking is cancelled and can no longer be moved.');
        }

        $type = $booking->getMeetingType();
        $startRaw = (string) ($this->decode($request)['start'] ?? '');
        if ($startRaw === '') {
            throw new BadRequestHttpException('start is required.');
        }
        try {
            $start = (new \DateTimeImmutable($startRaw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new BadRequestHttpException('Invalid start.');
        }
        $end = $start->modify('+' . $type->getDurationMinutes() . ' minutes');

        // Re-validate the target slot (grid + notice window + free), then move it
        // under a transaction with an overlap re-check that ignores this booking.
        if (!$this->slots->isBookable($type, $start)) {
            throw new ConflictHttpException('This slot is no longer available.');
        }

        $oldStart = $booking->getStartAt();
        $this->em->wrapInTransaction(function () use ($type, $booking, $start, $end): void {
            if ($this->bookings->hasConfirmedOverlap($type, $start, $end, $booking)) {
                throw new ConflictHttpException('This slot was just taken.');
            }
            $booking->setStartAt($start)->setEndAt($end)->incrementRescheduledCount();
        });

        $this->mailer->sendReschedule($booking, $oldStart);

        return new JsonResponse([
            'success' => true,
            'cancelToken' => $booking->getCancelToken(),
            'start' => $start->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function requireType(string $slug): MeetingType
    {
        $type = $this->meetingTypes->findOneEnabledBySlug($slug);
        if ($type === null) {
            throw new NotFoundHttpException();
        }

        return $type;
    }

    /** @return array<string, mixed> */
    private function typeDto(MeetingType $type): array
    {
        return [
            'slug' => $type->getSlug(),
            'title' => $type->getTitle(),
            'description' => $type->getDescription(),
            'durationMinutes' => $type->getDurationMinutes(),
            'locationType' => $type->getLocationType(),
            'timezone' => $type->getTimezone(),
            'hostName' => $type->getHost()?->getFullName(),
        ];
    }

    private function cleanTz(mixed $tz): ?string
    {
        if (!\is_string($tz) || $tz === '') {
            return null;
        }

        return \in_array($tz, timezone_identifiers_list(), true) ? $tz : null;
    }

    /** @return array<string, mixed> */
    private function decode(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
