<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Admin edit of a member's underlying {@see User} (name + email), scoped by the
 * member's workspace.
 *
 *   PATCH /v1/workspace_members/{id}/profile   { firstName?, lastName?, email? }
 *
 * The User ApiResource is deliberately read-only (writes go through dedicated,
 * allow-listed controllers to avoid privilege escalation). This lets a workspace
 * manager correct a colleague's name/email without opening a generic user write.
 * Gated on MANAGE of the member's workspace; email must stay unique (it is the
 * login identifier). Admin-initiated — no re-verification mail (mirrors the
 * still-deferred self-service email change, but an admin is trusted here).
 */
final class WorkspaceMemberProfileController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/workspace_members/{id}/profile',
        name: 'api_workspace_member_profile_patch',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['PATCH'],
    )]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->security->getUser() instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        try {
            $member = $this->em->find(WorkspaceMember::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid member id.');
        }
        if ($member === null) {
            throw new NotFoundHttpException('Member not found.');
        }
        if (!$this->security->isGranted('MANAGE', $member->getWorkspace())) {
            throw new AccessDeniedHttpException('You cannot manage this workspace.');
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($body)) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        $target = $member->getUser();

        if (array_key_exists('firstName', $body)) {
            $v = $body['firstName'];
            if (!is_string($v)) {
                throw new BadRequestHttpException('firstName must be a string.');
            }
            $t = trim($v);
            if (mb_strlen($t) > 80) {
                throw new BadRequestHttpException('firstName is too long.');
            }
            $target->setFirstName($t);
        }
        if (array_key_exists('lastName', $body)) {
            $v = $body['lastName'];
            if (!is_string($v)) {
                throw new BadRequestHttpException('lastName must be a string.');
            }
            $t = trim($v);
            if (mb_strlen($t) > 80) {
                throw new BadRequestHttpException('lastName is too long.');
            }
            $target->setLastName($t);
        }
        if (array_key_exists('email', $body)) {
            $v = $body['email'];
            if (!is_string($v)) {
                throw new BadRequestHttpException('email must be a string.');
            }
            $email = trim($v);
            if ($email === '' || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
                throw new BadRequestHttpException('email is not a valid address.');
            }
            if (mb_strtolower($email) !== mb_strtolower($target->getEmail())) {
                // Uniqueness — email is the login identifier. Reject if another
                // user already owns it (case-insensitive to be safe).
                $existing = $this->em->getRepository(User::class)->createQueryBuilder('u')
                    ->where('LOWER(u.email) = :email')
                    ->setParameter('email', mb_strtolower($email))
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
                if ($existing !== null && $existing->getId()?->toRfc4122() !== $target->getId()?->toRfc4122()) {
                    throw new ConflictHttpException('This email address is already in use.');
                }
                $target->setEmail($email);
            }
        }

        $this->em->flush();

        return new JsonResponse([
            'id' => $target->getId()?->toRfc4122(),
            'email' => $target->getEmail(),
            'firstName' => $target->getFirstName(),
            'lastName' => $target->getLastName(),
            'fullName' => trim($target->getFirstName() . ' ' . $target->getLastName()),
        ]);
    }
}
