<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Service\Portal\PortalAccessResolver;
use App\Service\Portal\PortalNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Customer-portal notifications — the header 🔔 bell + feed. The feed is derived
 * on read from real signals (see {@see PortalNotificationService}); the only
 * stored state is the contact's "seen at" marker, set by mark-read to clear the
 * unread badge. Always available to a portal user (aggregates only from enabled
 * features); no dedicated feature flag.
 */
final class PortalNotificationsController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly PortalNotificationService $notifications,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/portal/notifications',
        name: 'api_portal_notifications_list',
        host: 'api.worktide.ddev.site',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        return new JsonResponse($this->notifications->feed());
    }

    #[Route(
        path: '/v1/portal/notifications/mark-read',
        name: 'api_portal_notifications_mark_read',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function markRead(): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        $this->portal->contact()->setPortalNotificationsSeenAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(['unreadCount' => 0]);
    }
}
