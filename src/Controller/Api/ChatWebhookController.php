<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Notification\Chat\ChatWebhookService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Staff self-service for their own chat-notification webhook (Slack/Mattermost/
 * Teams). The URL is write-only — GET reports only whether one is configured.
 *
 *   GET    /v1/me/chat-webhook       → { provider, enabled, configured }
 *   PUT    /v1/me/chat-webhook       → set { provider, url, enabled }
 *   DELETE /v1/me/chat-webhook       → remove
 *   POST   /v1/me/chat-webhook/test  → send a test message now
 */
final class ChatWebhookController
{
    public function __construct(
        private readonly Security $security,
        private readonly ChatWebhookService $service,
        private readonly string $spaBaseUrl,
    ) {}

    #[Route(path: '/v1/me/chat-webhook', name: 'api_me_chat_webhook_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse($this->service->status($this->user()));
    }

    #[Route(path: '/v1/me/chat-webhook', name: 'api_me_chat_webhook_put', methods: ['PUT'])]
    public function put(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);

        return new JsonResponse($this->service->save($this->user(), \is_array($body) ? $body : []));
    }

    #[Route(path: '/v1/me/chat-webhook', name: 'api_me_chat_webhook_delete', methods: ['DELETE'])]
    public function delete(): JsonResponse
    {
        $this->service->delete($this->user());

        return new JsonResponse(['deleted' => true]);
    }

    #[Route(path: '/v1/me/chat-webhook/test', name: 'api_me_chat_webhook_test', methods: ['POST'])]
    public function test(): JsonResponse
    {
        return new JsonResponse(['sent' => $this->service->test($this->user(), $this->spaBaseUrl)]);
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
