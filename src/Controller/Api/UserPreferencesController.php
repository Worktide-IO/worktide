<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserPreferences;
use App\Repository\UserPreferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET + PUT for the current user's UI preferences.
 *
 * Lives on `/v1/me/preferences` instead of being exposed as a generic
 * ApiResource so the route IS the authorization: every request reads /
 * writes the authenticated user's own row. No way to ask for someone
 * else's prefs by id.
 *
 * Returning {dashboardLayout: null} on first access (instead of 404) is
 * deliberate — the SPA renders a sensible default layout when null and
 * only PUT-creates a row once the user changes something. Saves the
 * dance of "GET 404 → POST empty → resume".
 *
 * Mercure broadcasts a per-user topic on PUT so other tabs of the same
 * user pick up layout changes without polling. The topic deliberately
 * embeds the user IRI so the SPA can subscribe-once for the lifetime of
 * the session.
 */
final class UserPreferencesController
{
    public function __construct(
        private readonly Security $security,
        private readonly UserPreferencesRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly HubInterface $hub,
    ) {}

    #[Route(
        path: '/v1/me/preferences',
        name: 'api_me_preferences_get',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function get(): JsonResponse
    {
        $user = $this->requireUser();
        $prefs = $this->repo->findOneByUser($user);

        return new JsonResponse([
            'dashboardLayout' => $prefs?->getDashboardLayout(),
            'idleTimeoutMinutes' => $prefs?->getIdleTimeoutMinutes(),
            'favoriteProjectIds' => $prefs?->getFavoriteProjectIds() ?? [],
            'updatedAt' => $prefs?->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route(
        path: '/v1/me/preferences',
        name: 'api_me_preferences_put',
        host: 'api.worktide.ddev.site',
        methods: ['PUT'],
    )]
    public function put(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            throw new BadRequestHttpException('Body must be a JSON object.');
        }

        if (array_key_exists('dashboardLayout', $body)) {
            $layout = $body['dashboardLayout'];
            if ($layout !== null && !is_array($layout)) {
                throw new BadRequestHttpException('dashboardLayout must be an object or null.');
            }
        } else {
            $layout = null;
        }

        $prefs = $this->repo->findOneByUser($user);
        if ($prefs === null) {
            $prefs = new UserPreferences($user);
            $this->em->persist($prefs);
        }
        if (array_key_exists('dashboardLayout', $body)) {
            $prefs->setDashboardLayout($layout);
        }
        if (array_key_exists('idleTimeoutMinutes', $body)) {
            $raw = $body['idleTimeoutMinutes'];
            if ($raw === null) {
                $prefs->setIdleTimeoutMinutes(null);
            } elseif (is_int($raw) && $raw >= 1 && $raw <= 480) {
                $prefs->setIdleTimeoutMinutes($raw);
            } else {
                throw new BadRequestHttpException(
                    'idleTimeoutMinutes must be null or an integer between 1 and 480.',
                );
            }
        }
        if (array_key_exists('favoriteProjectIds', $body)) {
            $raw = $body['favoriteProjectIds'];
            if ($raw === null || $raw === []) {
                $prefs->setFavoriteProjectIds(null);
            } elseif (is_array($raw)) {
                $clean = [];
                foreach ($raw as $id) {
                    if (!is_string($id) || !preg_match('/^[0-9a-f-]{36}$/i', $id)) {
                        throw new BadRequestHttpException(
                            'favoriteProjectIds must be a list of UUID strings.',
                        );
                    }
                    if (!in_array($id, $clean, true)) {
                        $clean[] = $id;
                    }
                }
                // Hard cap to avoid runaway accumulation — 200 favourites
                // is way more than any human realistically curates.
                $prefs->setFavoriteProjectIds(array_slice($clean, 0, 200));
            } else {
                throw new BadRequestHttpException('favoriteProjectIds must be an array.');
            }
        }
        $this->em->flush();

        $userIri = '/v1/users/' . $user->getId()?->toRfc4122();
        $this->hub->publish(new Update(
            topics: [$userIri . '/preferences'],
            data: json_encode([
                'dashboardLayout' => $prefs->getDashboardLayout(),
                'idleTimeoutMinutes' => $prefs->getIdleTimeoutMinutes(),
                'favoriteProjectIds' => $prefs->getFavoriteProjectIds() ?? [],
                'updatedAt' => $prefs->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            ]) ?: '{}',
            private: true,
        ));

        return new JsonResponse([
            'dashboardLayout' => $prefs->getDashboardLayout(),
            'idleTimeoutMinutes' => $prefs->getIdleTimeoutMinutes(),
            'favoriteProjectIds' => $prefs->getFavoriteProjectIds() ?? [],
            'updatedAt' => $prefs->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ]);
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
