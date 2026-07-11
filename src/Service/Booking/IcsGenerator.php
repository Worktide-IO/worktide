<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Entity\Booking;

/**
 * Minimal hand-rolled iCalendar (RFC 5545) VEVENT for a {@see Booking}, attached
 * to the confirmation email so the invitee can add it to their calendar. Kept
 * dependency-free — a single VEVENT with escaped text is all mainstream clients
 * (Google/Outlook/Apple) need.
 */
final class IcsGenerator
{
    public function __construct(
        private readonly string $mailFrom,
    ) {}

    public function forBooking(Booking $booking): string
    {
        $type = $booking->getMeetingType();
        $host = $type->getHost();
        $organizerEmail = $host?->getEmail() ?: $this->mailFrom;
        $organizerName = $host?->getFullName() ?: 'Worktide';

        $location = trim(($type->getLocationType() === 'video' ? 'Video' : ($type->getLocationType() === 'phone' ? 'Telefon' : 'Vor Ort'))
            . ' ' . ($type->getLocationDetail() ?? ''));

        $uid = ($booking->getId()?->toRfc4122() ?? bin2hex(random_bytes(8))) . '@worktide';
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');

        // SEQUENCE must strictly increase for a client to accept an update: one
        // per reschedule, plus a final bump on cancellation.
        $sequence = $booking->getRescheduledCount() + ($booking->isCancelled() ? 1 : 0);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Worktide//Booking//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:' . ($booking->isCancelled() ? 'CANCEL' : 'REQUEST'),
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $stamp,
            'DTSTART:' . $booking->getStartAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'DTEND:' . $booking->getEndAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->esc($type->getTitle()),
            'DESCRIPTION:' . $this->esc($type->getDescription() ?? ''),
            'LOCATION:' . $this->esc($location),
            'ORGANIZER;CN=' . $this->esc($organizerName) . ':mailto:' . $organizerEmail,
            'ATTENDEE;CN=' . $this->esc($booking->getInviteeName()) . ';RSVP=TRUE:mailto:' . $booking->getInviteeEmail(),
            'STATUS:' . ($booking->isCancelled() ? 'CANCELLED' : 'CONFIRMED'),
            'SEQUENCE:' . $sequence,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        // RFC 5545 wants CRLF line endings.
        return implode("\r\n", $lines) . "\r\n";
    }

    /** Escape per RFC 5545 §3.3.11 (backslash, comma, semicolon, newlines). */
    private function esc(string $value): string
    {
        return str_replace(
            ['\\', ',', ';', "\r\n", "\n", "\r"],
            ['\\\\', '\\,', '\\;', '\\n', '\\n', '\\n'],
            $value,
        );
    }
}
