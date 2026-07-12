<?php

declare(strict_types=1);

namespace App\Service\Booking;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\Booking;
use App\Service\I18n\RecipientLocaleResolver;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
        private readonly RecipientLocaleResolver $localeResolver,
        private readonly string $portalBaseUrl,
        private readonly string $mailFrom,
        private readonly string $mailFromName = '',
    ) {}

    public function sendConfirmation(Booking $booking): void
    {
        $this->send($booking, 'confirmation');
    }

    public function sendCancellation(Booking $booking): void
    {
        $this->send($booking, 'cancelled');
    }

    /**
     * Confirms the moved appointment and shows where it came from. `$oldStart`
     * is the pre-move start, already stamped in the app tz.
     */
    public function sendReschedule(Booking $booking, \DateTimeImmutable $oldStart): void
    {
        $this->send($booking, 'rescheduled', $oldStart);
    }

    private function send(Booking $booking, string $template, ?\DateTimeImmutable $oldStart = null): void
    {
        if ($booking->getInviteeEmail() === '' || !$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return;
        }

        $type = $booking->getMeetingType();
        $tz = new \DateTimeZone($booking->getInviteeTimezone() ?: $type->getTimezone());
        $flow = 'booking_' . $template;

        // The invitee is anonymous (no stored preference) — use the booking's
        // workspace language. Rendered async (Messenger), so the locale travels
        // in the context and templates apply it via the trans filter.
        $locale = $this->localeResolver->forWorkspace($booking->getWorkspace());

        // Only confirmation / reschedule surface the location line.
        $location = $template === 'cancelled'
            ? ''
            : $this->locationLabel($type->getLocationType(), $type->getLocationDetail(), $flow, $locale);

        $mail = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $this->mailFromName !== '' ? $this->mailFromName : 'Worktide'))
            ->to(new Address($booking->getInviteeEmail(), $booking->getInviteeName()))
            ->subject($this->translator->trans("email.{$flow}.subject", ['%title%' => $type->getTitle()], null, $locale))
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
                'location' => $location,
                'notes' => $booking->getNotes(),
                'cancelUrl' => rtrim($this->portalBaseUrl, '/') . '/book/cancel/' . $booking->getCancelToken(),
                'rescheduleUrl' => rtrim($this->portalBaseUrl, '/') . '/book/reschedule/' . $booking->getCancelToken(),
                'oldStart' => $oldStart?->setTimezone($tz),
                'locale' => $locale,
            ]);

        // Attach the calendar invite (only meaningful for a live booking).
        if (!$booking->isCancelled()) {
            $mail->addPart(new DataPart($this->ics->forBooking($booking), 'termin.ics', 'text/calendar'));
        }

        $this->mailer->send($mail);
    }

    private function locationLabel(string $type, ?string $detail, string $flow, string $locale): string
    {
        $key = match ($type) {
            'phone' => 'location_phone',
            'in_person' => 'location_in_person',
            default => 'location_video',
        };

        $base = $this->translator->trans("email.{$flow}.{$key}", [], null, $locale);

        return $detail !== null && $detail !== '' ? "$base — $detail" : $base;
    }
}
