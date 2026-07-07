<?php

declare(strict_types=1);

namespace App\Service;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\WorkspaceInvitation;
use App\Service\Branding\BrandingConfig;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sends the branded workspace-invitation email carrying the magic accept link
 * ({SPA_BASE_URL}/accept-invitation?token=…). Dispatch is gated by the
 * default-deny egress guard (EmailOutbound); when it actually leaves the
 * system the invitation records the send (sentAt + sendCount) for tracking.
 *
 * The caller is responsible for flushing the entity afterwards — this service
 * only mutates the in-memory entity so it can be reused both from the API
 * persist processor (auto-send on create) and the resend endpoint.
 */
final readonly class WorkspaceInvitationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private EgressGuard $egress,
        private BrandingConfig $branding,
        private string $spaBaseUrl,
        private string $mailFrom,
        private string $mailFromName,
    ) {}

    /**
     * Dispatch (or re-dispatch) the invitation email. Returns true when the
     * mail actually left the system, false when egress is disabled — callers
     * must not branch on delivery beyond surfacing "sent" vs "not sent".
     */
    public function send(WorkspaceInvitation $invitation): bool
    {
        if (!$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return false;
        }

        $acceptUrl = rtrim($this->spaBaseUrl, '/') . '/accept-invitation?token=' . $invitation->getToken();

        $mail = (new TemplatedEmail())
            ->from($this->fromAddress())
            ->to($invitation->getEmail())
            ->subject(sprintf('Einladung zu %s', $this->branding->name()))
            ->htmlTemplate('email/workspace_invitation.html.twig')
            ->textTemplate('email/workspace_invitation.txt.twig')
            ->context([
                'acceptUrl' => $acceptUrl,
                'workspaceName' => $invitation->getWorkspace()->getName(),
                'role' => $invitation->getRole()->value,
                'expiresAt' => $invitation->getExpiresAt(),
            ]);

        // Routed async via Messenger (SendEmailMessage: async).
        $this->mailer->send($mail);
        $invitation->markSent();

        return true;
    }

    /** Branded "From" — display name (MAILER_FROM_NAME, default product name) + address. */
    private function fromAddress(): Address
    {
        $name = $this->mailFromName !== '' ? $this->mailFromName : $this->branding->name();

        return new Address($this->mailFrom, $name);
    }
}
