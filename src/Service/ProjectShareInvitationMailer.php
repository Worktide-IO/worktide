<?php

declare(strict_types=1);

namespace App\Service;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\ProjectShareInvitation;
use App\Service\Branding\BrandingConfig;
use App\Service\I18n\RecipientLocaleResolver;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends the branded project-share invitation carrying the magic accept link
 * ({SPA_BASE_URL}/accept-project-share?token=…). Egress-gated (EmailOutbound);
 * records send tracking on the entity. Caller flushes. Mirrors
 * {@see WorkspaceInvitationMailer}.
 */
final readonly class ProjectShareInvitationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private EgressGuard $egress,
        private BrandingConfig $branding,
        private TranslatorInterface $translator,
        private RecipientLocaleResolver $localeResolver,
        private string $spaBaseUrl,
        private string $mailFrom,
        private string $mailFromName,
    ) {}

    public function send(ProjectShareInvitation $invitation): bool
    {
        if (!$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return false;
        }

        $acceptUrl = rtrim($this->spaBaseUrl, '/') . '/accept-project-share?token=' . $invitation->getToken();

        // The invitee is identified only by email and has no stored preference —
        // use the sharing project's workspace language. Rendered async
        // (Messenger), so the locale travels in the context and templates apply
        // it via the trans filter.
        $locale = $this->localeResolver->forWorkspace($invitation->getProject()->getWorkspace());

        $mail = (new TemplatedEmail())
            ->from($this->fromAddress())
            ->to($invitation->getEmail())
            ->subject($this->translator->trans(
                'email.project_share.subject',
                ['%project%' => $invitation->getProject()->getName()],
                null,
                $locale,
            ))
            ->htmlTemplate('email/project_share_invitation.html.twig')
            ->textTemplate('email/project_share_invitation.txt.twig')
            ->context([
                'acceptUrl' => $acceptUrl,
                'projectName' => $invitation->getProject()->getName(),
                'sharingWorkspaceName' => $invitation->getWorkspace()->getName(),
                'role' => $invitation->getRole()->value,
                'expiresAt' => $invitation->getExpiresAt(),
                'locale' => $locale,
            ]);

        $this->mailer->send($mail);
        $invitation->markSent();

        return true;
    }

    private function fromAddress(): Address
    {
        $name = $this->mailFromName !== '' ? $this->mailFromName : $this->branding->name();

        return new Address($this->mailFrom, $name);
    }
}
