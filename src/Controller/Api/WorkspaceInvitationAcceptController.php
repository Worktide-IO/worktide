<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\InvitationStatus;
use App\Entity\User;
use App\Entity\WorkspaceInvitation;
use App\Entity\WorkspaceMember;
use App\Repository\UserRepository;
use App\Repository\WorkspaceInvitationRepository;
use App\Repository\WorkspaceMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accept a workspace invitation:
 *
 *   POST /v1/workspace_invitations/{token}/accept
 *   Body: { "firstName": "...", "lastName": "...", "password": "..." }     (only when no User yet)
 *         {}                                                                (when the email already maps to a User)
 *
 * Idempotent semantics: if the email maps to an existing User, we just add
 * the WorkspaceMember row (if missing) and mark the invitation accepted.
 * If no User exists yet, we create one — password is mandatory in that branch
 * so the invitee can log in afterwards.
 *
 * Returns a JWT so the client can transition straight into the workspace
 * without an extra /v1/auth/login round-trip.
 *
 * This endpoint is intentionally PUBLIC (the token IS the credential).
 */
final class WorkspaceInvitationAcceptController
{
    public function __construct(
        private readonly WorkspaceInvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly WorkspaceMemberRepository $wsMembers,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JWTTokenManagerInterface $jwt,
    ) {}

    #[Route(
        path: '/v1/workspace_invitations/{token}/accept',
        name: 'api_workspace_invitation_accept',
        requirements: ['token' => '[A-Za-z0-9]{32,128}'],
        methods: ['POST'],
    )]
    public function __invoke(string $token, Request $request): JsonResponse
    {
        $invitation = $this->invitations->findByToken($token);
        if ($invitation === null) {
            throw new NotFoundHttpException('Invitation not found.');
        }
        if (!$invitation->isAcceptable(new \DateTimeImmutable())) {
            $this->em->flush();
            throw new BadRequestHttpException(sprintf(
                'Invitation cannot be accepted (status=%s).',
                $invitation->getStatus()->value,
            ));
        }

        $payload = $this->payload($request);
        $user = $this->users->findOneBy(['email' => $invitation->getEmail()])
            ?? $this->createUser($invitation, $payload);

        $existing = $this->wsMembers->findOneBy([
            'workspace' => $invitation->getWorkspace(),
            'user' => $user,
        ]);
        if ($existing === null) {
            $member = (new WorkspaceMember())
                ->setWorkspace($invitation->getWorkspace())
                ->setUser($user)
                ->setRole($invitation->getRole());
            $this->em->persist($member);
        }

        $invitation->markAccepted($user);
        $this->em->flush();

        return new JsonResponse([
            'invitationId' => $invitation->getId()?->toRfc4122(),
            'workspaceId' => $invitation->getWorkspace()->getId()?->toRfc4122(),
            'userId' => $user->getId()?->toRfc4122(),
            'token' => $this->jwt->create($user),
        ], 200);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be valid JSON.');
        }
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createUser(WorkspaceInvitation $invitation, array $payload): User
    {
        $password = $payload['password'] ?? null;
        if (!\is_string($password) || \strlen($password) < 6) {
            throw new BadRequestHttpException(
                'No account exists for this email yet — supply firstName / lastName / password to create one.',
            );
        }
        $user = (new User())
            ->setEmail($invitation->getEmail())
            ->setFirstName((string) ($payload['firstName'] ?? ''))
            ->setLastName((string) ($payload['lastName'] ?? ''));
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();  // flush so $user->getId() is available
        return $user;
    }
}
