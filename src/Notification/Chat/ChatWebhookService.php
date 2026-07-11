<?php

declare(strict_types=1);

namespace App\Notification\Chat;

use App\Entity\Enum\ChatProvider;
use App\Entity\User;
use App\Entity\UserChatWebhook;
use App\Http\OutboundUrlGuard;
use App\Http\UnsafeUrlException;
use App\Repository\UserChatWebhookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Self-service management of a user's chat webhook — shared by the staff
 * ({@see \App\Controller\Api\ChatWebhookController}) and portal
 * ({@see \App\Controller\Api\Portal\PortalChatWebhookController}) endpoints so
 * both behave identically.
 *
 * The URL is never echoed back (only whether one is `configured`); it's validated
 * as a safe public http(s) target on save + encrypted at rest.
 */
final class ChatWebhookService
{
    public function __construct(
        private readonly UserChatWebhookRepository $webhooks,
        private readonly EntityManagerInterface $em,
        private readonly OutboundUrlGuard $urlGuard,
        private readonly ChatWebhookSender $sender,
    ) {}

    /** @return array{provider: string|null, enabled: bool, configured: bool} */
    public function status(User $user): array
    {
        $webhook = $this->webhooks->findOneByUser($user);

        return [
            'provider' => $webhook?->getProvider()->value,
            'enabled' => $webhook?->isEnabled() ?? false,
            'configured' => $webhook !== null,
        ];
    }

    /**
     * Create/replace the user's webhook.
     *
     * @param array<string, mixed> $body
     *
     * @return array{provider: string|null, enabled: bool, configured: bool}
     */
    public function save(User $user, array $body): array
    {
        $provider = ChatProvider::tryFrom((string) ($body['provider'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        if ($provider === null) {
            throw new BadRequestHttpException('Unknown chat provider.');
        }
        if ($url === '') {
            throw new BadRequestHttpException('A webhook URL is required.');
        }
        try {
            $this->urlGuard->assertPublicHttpUrl($url);
        } catch (UnsafeUrlException $e) {
            throw new BadRequestHttpException('Webhook URL is not a safe public URL: ' . $e->getMessage());
        }

        $webhook = $this->webhooks->findOneByUser($user) ?? (new UserChatWebhook())->setUser($user);
        $webhook->setProvider($provider)->setUrl($url)->setEnabled(($body['enabled'] ?? true) !== false);
        $this->em->persist($webhook);
        $this->em->flush();

        return $this->status($user);
    }

    public function delete(User $user): void
    {
        $webhook = $this->webhooks->findOneByUser($user);
        if ($webhook !== null) {
            $this->em->remove($webhook);
            $this->em->flush();
        }
    }

    /** Send a test message now; returns whether it was delivered (2xx). */
    public function test(User $user, string $appBaseUrl): bool
    {
        $webhook = $this->webhooks->findOneByUser($user);
        if ($webhook === null) {
            throw new BadRequestHttpException('No chat webhook configured.');
        }

        return $this->sender->send(
            $webhook,
            'Test von Worktide',
            'Wenn Sie diese Nachricht sehen, ist die Chat-Verbindung eingerichtet.',
            rtrim($appBaseUrl, '/') . '/',
        );
    }
}
