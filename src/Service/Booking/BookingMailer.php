<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Booking;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Booking confirmation / cancellation emails to the (anonymous) invitee.
 *
 * Mirrors WorkspaceInvitationMailer: branded TemplatedEmail, async via Messenger,
 * behind the default-deny EmailOutbound egress gate (which is a module toggle,
 * NOT a recipient allowlist — sending to an arbitrary external invitee is fine
 * once `email_outbound` is enabled). The confirmation carries an .ics part so the
 * invitee can one-click add it to their calendar. Best-effort — a mail failure
 * must never abort the booking.
 */
final class BookingMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EgressGuard $egress,
        private readonly IcsGenerator $ics,
        private readonly string $portalBaseUrl,
        private readonly string $mailFrom,
        private readonly string $mailFromName = '',
    ) {}

    public function sendConfirmation(Booking $booking): void
    {
        $this->send($booking, 'confirmation', 'Terminbestätigung');
    }

    public function sendCancellation(Booking $booking): void
    {
        $this->send($booking, 'cancelled', 'Termin storniert');
    }

    private function send(Booking $booking, string $template, string $subjectPrefix): void
    {
        if ($booking->getInviteeEmail() === '' || !$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return;
        }

        $type = $booking->getMeetingType();
        $tz = new \DateTimeZone($booking->getInviteeTimezone() ?: $type->getTimezone());

        $mail = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $this->mailFromName !== '' ? $this->mailFromName : 'Worktide'))
            ->to(new Address($booking->getInviteeEmail(), $booking->getInviteeName()))
            ->subject($subjectPrefix . ': ' . $type->getTitle())
            ->htmlTemplate("email/booking_{$template}.html.twig")
            ->textTemplate("email/booking_{$template}.txt.twig")
            ->context([
                'title' => $type->getTitle(),
                'inviteeName' => $booking->getInviteeName(),
                'start' => $booking->getStartAt()->setTimezone($tz),
                'end' => $booking->getEndAt()->setTimezone($tz),
                'timezone' => $tz->getName(),
                'durationMinutes' => $type->getDurationMinutes(),
                'hostName' => $type->getHost()?->getFullName(),
                'location' => $this->locationLabel($type->getLocationType(), $type->getLocationDetail()),
                'notes' => $booking->getNotes(),
                'cancelUrl' => rtrim($this->portalBaseUrl, '/') . '/book/cancel/' . $booking->getCancelToken(),
            ]);

        // Attach the calendar invite (only meaningful for a live booking).
        if (!$booking->isCancelled()) {
            $mail->addPart(new DataPart($this->ics->forBooking($booking), 'termin.ics', 'text/calendar'));
        }

        $this->mailer->send($mail);
    }

    private function locationLabel(string $type, ?string $detail): string
    {
        $base = match ($type) {
            'phone' => 'Telefon',
            'in_person' => 'Vor Ort',
            default => 'Videocall',
        };

        return $detail !== null && $detail !== '' ? "$base — $detail" : $base;
    }
}
