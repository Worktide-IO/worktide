<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Notification\NotificationFeedService;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Staff notification inbox — `/v1/me/notifications`.
 *
 * Like {@see UserPreferencesController}, the route IS the authorization: every
 * operation is scoped to the authenticated user's own rows (the repository
 * WHEREs on `recipient`), so there is no way to read or mark another user's
 * notifications by id. Firewall already requires ROLE_USER on `^/v1`.
 */
final class NotificationsController
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationFeedService $feed,
        private readonly NotificationRepository $repo,
    ) {}

    #[Route(path: '/v1/me/notifications', name: 'api_me_notifications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireUser();

        return new JsonResponse($this->feed->feed(
            $user,
            $request->query->get('cursor'),
            $request->query->getInt('limit', NotificationFeedService::DEFAULT_LIMIT),
            $request->query->getBoolean('unread'),
        ));
    }

    #[Route(path: '/v1/me/notifications/{id}/read', name: 'api_me_notifications_read', methods: ['POST'])]
    public function markRead(string $id): JsonResponse
    {
        $user = $this->requireUser();
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid id.'], 400);
        }
        $this->repo->markRead($user, Uuid::fromString($id));

        return new JsonResponse(['unreadCount' => $this->repo->countUnread($user)]);
    }

    #[Route(path: '/v1/me/notifications/read-all', name: 'api_me_notifications_read_all', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        $user = $this->requireUser();
        $this->repo->markAllRead($user);

        return new JsonResponse(['unreadCount' => 0]);
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
