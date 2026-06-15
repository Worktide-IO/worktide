<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Profile + password endpoints scoped to the authenticated user.
 *
 * Lives on `/v1/me/*` instead of relying on PATCH /v1/users/{id} so:
 *   - the URL is the authorisation — no way to slip another user's id in
 *   - the allowed-fields list is explicit and small (defence against the
 *     generic ApiResource exposing too much surface)
 *   - password hashing is mandatory at write time, not "hopefully your
 *     state processor does it"
 *
 * Three routes:
 *   GET   /v1/me/profile   → current profile (name, email, lastLoginAt)
 *   PATCH /v1/me/profile   → update firstName / lastName
 *   POST  /v1/me/password  → change password, requires `currentPassword`
 */
final class MeProfileController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    #[Route(
        path: '/v1/me/profile',
        name: 'api_me_profile_get',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function get(): JsonResponse
    {
        $user = $this->requireUser();
        return new JsonResponse($this->snapshot($user));
    }

    #[Route(
        path: '/v1/me/profile',
        name: 'api_me_profile_patch',
        host: 'api.worktide.ddev.site',
        methods: ['PATCH'],
    )]
    public function patch(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $body = $this->body($request);

        // Allowlist — anything else in the body is silently ignored so
        // an old or future client can't surprise-set a field.
        if (array_key_exists('firstName', $body)) {
            $value = $body['firstName'];
            if (!is_string($value)) {
                throw new BadRequestHttpException('firstName must be a string.');
            }
            $trimmed = trim($value);
            if (mb_strlen($trimmed) > 80) {
                throw new BadRequestHttpException('firstName is too long.');
            }
            $user->setFirstName($trimmed);
        }
        if (array_key_exists('lastName', $body)) {
            $value = $body['lastName'];
            if (!is_string($value)) {
                throw new BadRequestHttpException('lastName must be a string.');
            }
            $trimmed = trim($value);
            if (mb_strlen($trimmed) > 80) {
                throw new BadRequestHttpException('lastName is too long.');
            }
            $user->setLastName($trimmed);
        }

        $this->em->flush();
        return new JsonResponse($this->snapshot($user));
    }

    #[Route(
        path: '/v1/me/password',
        name: 'api_me_password_post',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function password(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $body = $this->body($request);

        $current = is_string($body['currentPassword'] ?? null) ? $body['currentPassword'] : null;
        $next = is_string($body['newPassword'] ?? null) ? $body['newPassword'] : null;
        if ($current === null || $current === '' || $next === null || $next === '') {
            throw new BadRequestHttpException('currentPassword + newPassword required.');
        }
        if (mb_strlen($next) < 8) {
            throw new BadRequestHttpException('newPassword must be at least 8 characters.');
        }
        if (!$this->hasher->isPasswordValid($user, $current)) {
            // Plain 400 (not 401) so the SPA can surface a form-level error
            // instead of falling into the auth-retry path.
            throw new BadRequestHttpException('currentPassword does not match.');
        }

        $user->setPassword($this->hasher->hashPassword($user, $next));
        $this->em->flush();

        return new JsonResponse(['changed' => true]);
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        return [
            'id' => $user->getId()?->toRfc4122(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'fullName' => $user->getFullName(),
            'roles' => $user->getRoles(),
            'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be JSON.');
        }
        return is_array($decoded) ? $decoded : [];
    }
}
