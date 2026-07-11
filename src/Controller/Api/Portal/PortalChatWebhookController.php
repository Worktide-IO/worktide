<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\User;
use App\Notification\Chat\ChatWebhookService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Portal twin of {@see \App\Controller\Api\ChatWebhookController} — a portal
 * contact's own chat webhook. Portal users are ROLE_PORTAL and can't reach the
 * staff `/v1/me/*` route (behind the `^/v1 → ROLE_USER` catch-all), so they get
 * this under `^/v1/portal`. The route IS the authorization: every op targets the
 * authenticated user's own row. Deep-links in test messages use the portal base.
 */
final class PortalChatWebhookController
{
    public function __construct(
        private readonly Security $security,
        private readonly ChatWebhookService $service,
        private readonly string $portalBaseUrl,
    ) {}

    #[Route(path: '/v1/portal/chat-webhook', name: 'api_portal_chat_webhook_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->service->status($this->user()));
    }

    #[Route(path: '/v1/portal/chat-webhook', name: 'api_portal_chat_webhook_put', methods: ['PUT'])]
    public function put(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);

        return new JsonResponse($this->service->save($this->user(), \is_array($body) ? $body : []));
    }

    #[Route(path: '/v1/portal/chat-webhook', name: 'api_portal_chat_webhook_delete', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        $this->service->delete($this->user());

        return new JsonResponse(['deleted' => true]);
    }

    #[Route(path: '/v1/portal/chat-webhook/test', name: 'api_portal_chat_webhook_test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        return new JsonResponse(['sent' => $this->service->test($this->user(), $this->portalBaseUrl)]);
    }

    private function user(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        return $user;
    }
}
