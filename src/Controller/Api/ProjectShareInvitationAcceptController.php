<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\InvitationStatus;
use App\Entity\ProjectShare;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ProjectShareInvitationRepository;
use App\Repository\ProjectShareRepository;
use App\Repository\WorkspaceMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Accept a cross-workspace project-share invitation:
 *
 *   POST /v1/project_share_invitations/{token}/accept
 *   Header: X-Workspace-Id: <target workspace B>
 *
 * Unlike the workspace invitation, the acceptor is an already-logged-in staff
 * user (auth required by the ^/v1 firewall). It links the shared project into
 * the acceptor's ACTIVE workspace B (a ProjectShare) — B must be one the user
 * belongs to and must not be the project's own workspace A. Idempotent: an
 * existing share is reused, the invitation is consumed either way.
 */
final class ProjectShareInvitationAcceptController
{
    public function __construct(
        private readonly ProjectShareInvitationRepository $invitations,
        private readonly ProjectShareRepository $shares,
        private readonly WorkspaceMemberRepository $wsMembers,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    #[Route(
        path: '/v1/project_share_invitations/{token}/accept',
        name: 'api_project_share_invitation_accept',
        requirements: ['token' => '[A-Za-z0-9]{32,128}'],
        methods: ['POST'],
    )]
    public function __invoke(string $token, Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        $invitation = $this->invitations->findOneByToken($token);
        if ($invitation === null) {
            throw new NotFoundHttpException('Invitation not found.');
        }
        if (!$invitation->isPending() || $invitation->isExpired()) {
            if ($invitation->isExpired() && $invitation->isPending()) {
                $invitation->setStatus(InvitationStatus::Expired);
                $this->em->flush();
            }
            throw new BadRequestHttpException(sprintf(
                'Invitation cannot be accepted (status=%s).',
                $invitation->getStatus()->value,
            ));
        }

        // Target workspace B = the acceptor's active workspace.
        $requested = $request->headers->get('X-Workspace-Id');
        if ($requested === null || $requested === '') {
            throw new BadRequestHttpException('X-Workspace-Id header required (the workspace to share into).');
        }
        try {
            $workspaceB = $this->em->find(Workspace::class, Uuid::fromString($requested));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid X-Workspace-Id.');
        }
        if ($workspaceB === null) {
            throw new NotFoundHttpException('Target workspace not found.');
        }

        // Acceptor must belong to B.
        if ($this->wsMembers->findOneBy(['workspace' => $workspaceB, 'user' => $user]) === null) {
            throw new AccessDeniedHttpException('You are not a member of the target workspace.');
        }

        // Cannot share a project into its own (host) workspace.
        $project = $invitation->getProject();
        $workspaceA = $project->getWorkspace();
        if ($workspaceA->getId()?->toRfc4122() === $workspaceB->getId()?->toRfc4122()) {
            throw new BadRequestHttpException('The project already lives in this workspace.');
        }

        $share = $this->shares->findOneBy(['project' => $project, 'sharedWithWorkspace' => $workspaceB]);
        if ($share === null) {
            $share = (new ProjectShare())
                ->setProject($project)
                ->setSharedWithWorkspace($workspaceB)
                ->setRole($invitation->getRole())
                ->setAcceptedBy($user);
            $this->em->persist($share);
        }

        $invitation->setStatus(InvitationStatus::Accepted);
        $invitation->setAcceptedAt(new \DateTimeImmutable());
        $invitation->setAcceptedBy($user);
        $this->em->flush();

        return new JsonResponse([
            'shareId' => $share->getId()?->toRfc4122(),
            'projectId' => $project->getId()?->toRfc4122(),
            'projectName' => $project->getName(),
            'workspaceId' => $workspaceB->getId()?->toRfc4122(),
        ], 200);
    }
}
