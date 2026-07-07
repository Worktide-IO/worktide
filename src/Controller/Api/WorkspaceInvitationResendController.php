<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\InvitationStatus;
use App\Entity\WorkspaceInvitation;
use App\Security\Voter\WorktidePermission;
use App\Service\WorkspaceInvitationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Re-send a pending workspace invitation:
 *
 *   POST /v1/workspace_invitations/{id}/resend
 *
 * Refreshes the expiry window and re-mails the branded invitation (same token —
 * it stays valid until accepted/revoked). Only Pending invitations qualify;
 * accepted/revoked/expired ones return 400. Requires MANAGE on the workspace.
 * The response mirrors the entity so the UI can update sentAt / sendCount.
 */
final readonly class WorkspaceInvitationResendController
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em,
        private WorkspaceInvitationMailer $mailer,
    ) {}

    #[Route(
        path: '/v1/workspace_invitations/{id}/resend',
        name: 'api_workspace_invitation_resend',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $invitation = $this->em->find(WorkspaceInvitation::class, Uuid::fromString($id));
        if ($invitation === null) {
            throw new NotFoundHttpException('Invitation not found.');
        }
        if (!$this->security->isGranted(WorktidePermission::MANAGE, $invitation->getWorkspace())) {
            throw new AccessDeniedHttpException();
        }
        if ($invitation->getStatus() !== InvitationStatus::Pending) {
            throw new BadRequestHttpException(sprintf(
                'Only pending invitations can be resent (status=%s).',
                $invitation->getStatus()->value,
            ));
        }

        // Give the invitee a fresh window from "now".
        $invitation->setExpiresAt(
            (new \DateTimeImmutable())->modify('+' . WorkspaceInvitation::DEFAULT_TTL_DAYS . ' days'),
        );

        $sent = $this->mailer->send($invitation);
        $this->em->flush();

        return new JsonResponse([
            'id' => $invitation->getId()?->toRfc4122(),
            'email' => $invitation->getEmail(),
            'status' => $invitation->getStatus()->value,
            'sent' => $sent,
            'sentAt' => $invitation->getSentAt()?->format(\DATE_ATOM),
            'sendCount' => $invitation->getSendCount(),
            'expiresAt' => $invitation->getExpiresAt()->format(\DATE_ATOM),
        ]);
    }
}
