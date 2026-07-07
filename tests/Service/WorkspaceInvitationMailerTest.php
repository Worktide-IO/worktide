<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Egress\EgressGuard;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Workspace;
use App\Entity\WorkspaceInvitation;
use App\Service\Branding\BrandingConfig;
use App\Service\WorkspaceInvitationMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Unit coverage for the workspace-invitation mailer: the branded template +
 * magic accept link are built, dispatch is gated by the egress guard, and a
 * successful send records the dispatch (sentAt + sendCount) for tracking.
 */
final class WorkspaceInvitationMailerTest extends TestCase
{
    private const SPA_BASE = 'https://app.example.test';

    public function testSendMailsBrandedInvitationAndRecordsDispatch(): void
    {
        $captured = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$captured): void {
                $captured = $email;
            });

        $invitation = $this->invitation();
        $service = $this->service($mailer, allowEmail: true);

        $sent = $service->send($invitation);

        self::assertTrue($sent);
        self::assertSame(1, $invitation->getSendCount());
        self::assertNotNull($invitation->getSentAt());

        self::assertInstanceOf(TemplatedEmail::class, $captured);
        self::assertSame('email/workspace_invitation.html.twig', $captured->getHtmlTemplate());
        $ctx = $captured->getContext();
        self::assertSame(self::SPA_BASE . '/accept-invitation?token=' . $invitation->getToken(), $ctx['acceptUrl']);
        self::assertSame('Acme', $ctx['workspaceName']);
    }

    public function testSendIsSuppressedWhenEgressDenied(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $invitation = $this->invitation();
        $sent = $this->service($mailer, allowEmail: false)->send($invitation);

        self::assertFalse($sent);
        self::assertSame(0, $invitation->getSendCount());
        self::assertNull($invitation->getSentAt());
    }

    private function invitation(): WorkspaceInvitation
    {
        $workspace = (new Workspace())->setName('Acme');

        return (new WorkspaceInvitation())
            ->setWorkspace($workspace)
            ->setEmail('invitee@example.test')
            ->setRole(WorkspaceMemberRole::Member)
            ->setToken(str_repeat('b', 40));
    }

    private function service(MailerInterface $mailer, bool $allowEmail): WorkspaceInvitationMailer
    {
        return new WorkspaceInvitationMailer(
            $mailer,
            new EgressGuard($allowEmail ? 'email_outbound' : ''),
            $this->branding(),
            self::SPA_BASE,
            'no-reply@example.test',
            '',
        );
    }

    private function branding(): BrandingConfig
    {
        return new BrandingConfig(
            name: 'Worktide',
            legalName: '',
            logoUrl: '',
            logoUrlDark: '',
            primaryColor: '#0F8C72',
            accentColor: '#E0623A',
            imprintUrl: '',
            privacyUrl: '',
            supportEmail: '',
            mailFrom: 'no-reply@example.test',
            mailFromName: '',
            defaultUri: 'https://api.example.test',
        );
    }
}
