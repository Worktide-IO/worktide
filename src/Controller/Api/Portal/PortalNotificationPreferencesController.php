<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\User;
use App\Entity\UserPreferences;
use App\Notification\Preference\NotificationPreferences;
use App\Repository\UserPreferencesRepository;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Portal self-service for notification delivery preferences.
 *
 * Portal users hold only ROLE_PORTAL and can't reach the staff
 * `/v1/me/preferences` (behind the `^/v1 → ROLE_USER` catch-all), so they get
 * this twin under `^/v1/portal`. It reads/writes the SAME shared
 * {@see UserPreferences} row (lazy-created), governing only the notification
 * block — the dashboard-layout / idle-timeout fields are staff-only and never
 * exposed here.
 *
 * The route IS the authorization: every request targets the authenticated
 * portal user's own row. The response is the normalised preference object
 * (see {@see NotificationPreferences::toArray()}).
 */
final class PortalNotificationPreferencesController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly Security $security,
        private readonly UserPreferencesRepository $repo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/portal/notification-preferences',
        name: 'api_portal_notification_preferences_get',
        methods: ['GET'],
    )]
    public function get(): JsonResponse
    {
        $this->portal->assertPortalEnabled();
        $user = $this->requireUser();

        $stored = $this->repo->findOneByUser($user)?->getNotificationPreferences();

        return new JsonResponse(NotificationPreferences::fromArray($stored)->toArray());
    }

    #[Route(
        path: '/v1/portal/notification-preferences',
        name: 'api_portal_notification_preferences_put',
        methods: ['PUT', 'PATCH'],
    )]
    public function put(Request $request): JsonResponse
    {
        $this->portal->assertPortalEnabled();
        $user = $this->requireUser();

        $body = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($body)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }
        $this->validate($body);

        $prefs = $this->repo->findOneByUser($user);
        if ($prefs === null) {
            $prefs = new UserPreferences($user);
            $this->em->persist($prefs);
        }

        // Merge the incoming (possibly partial) body over the current state,
        // then normalise — so a client can PATCH just `frequency` without
        // wiping per-type toggles.
        $current = NotificationPreferences::fromArray($prefs->getNotificationPreferences())->toArray();
        $merged = $this->merge($current, $body);
        $prefs->setNotificationPreferences(NotificationPreferences::fromArray($merged)->toArray());
        $this->em->flush();

        return new JsonResponse($prefs->getNotificationPreferences());
    }

    /**
     * Reject obviously-malformed input up front (the value object would
     * otherwise silently coerce it to a default, which is confusing via an API).
     *
     * @param array<string, mixed> $body
     */
    private function validate(array $body): void
    {
        if (\array_key_exists('frequency', $body)
            && !\in_array($body['frequency'], NotificationPreferences::FREQUENCIES, true)) {
            throw new BadRequestHttpException(
                'frequency must be one of: ' . implode(', ', NotificationPreferences::FREQUENCIES) . '.',
            );
        }
        if (\array_key_exists('quietHours', $body) && $body['quietHours'] !== null) {
            $q = $body['quietHours'];
            $ok = \is_array($q)
                && $this->isTime($q['start'] ?? null)
                && $this->isTime($q['end'] ?? null);
            if (!$ok) {
                throw new BadRequestHttpException('quietHours must be null or {start, end} as "HH:MM".');
            }
        }
        if (\array_key_exists('types', $body) && !\is_array($body['types'])) {
            throw new BadRequestHttpException('types must be an object of {type: bool}.');
        }
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function merge(array $current, array $incoming): array
    {
        foreach (['email', 'frequency', 'quietHours'] as $key) {
            if (\array_key_exists($key, $incoming)) {
                $current[$key] = $incoming[$key];
            }
        }
        if (isset($incoming['types']) && \is_array($incoming['types'])) {
            $current['types'] = array_merge(
                \is_array($current['types'] ?? null) ? $current['types'] : [],
                $incoming['types'],
            );
        }

        return $current;
    }

    private function isTime(mixed $v): bool
    {
        return \is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        return $user;
    }
}
