<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Channels\AdapterRegistry;
use App\Channels\Testable;
use App\Channels\TestResult;
use App\Entity\Channel;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * POST /v1/channels/{id}/test
 *
 * Smoke-test a channel's current config without touching its state.
 * Routes through {@see AdapterRegistry} to find the inbound (or
 * outbound — same instance for most adapters) and asks it to
 * self-test if it implements {@see Testable}.
 *
 * Never mutates the channel. The Last-Sync columns are intentionally
 * untouched so a manual test doesn't drift the cron's idea of
 * "last successful sync".
 *
 * Response is always 200 with a verdict object — `failed` results
 * are surfaced as data, not HTTP errors, so the SPA can render them
 * inline next to the form without an error-toast.
 */
final class ChannelTestController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly AdapterRegistry $registry,
    ) {}

    #[Route(
        path: '/v1/channels/{id}/test',
        name: 'api_channels_test',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }
        try {
            $channel = $this->em->find(Channel::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid channel UUID.');
        }
        if ($channel === null) {
            throw new NotFoundHttpException();
        }
        $member = $this->em->getRepository(WorkspaceMember::class)
            ->findOneBy(['user' => $user, 'workspace' => $channel->getWorkspace()]);
        if ($member === null) {
            throw new AccessDeniedHttpException();
        }

        // Prefer the inbound adapter; fall back to outbound for
        // outbound-only channels (transactional mailers, etc.).
        $adapter = $this->registry->tryInbound($channel->getAdapterCode())
            ?? $this->registry->tryOutbound($channel->getAdapterCode());
        if ($adapter === null) {
            return new JsonResponse([
                'status' => 'failed',
                'message' => sprintf('No adapter registered for code "%s".', $channel->getAdapterCode()),
            ]);
        }
        if (!$adapter instanceof Testable) {
            return new JsonResponse([
                'status' => 'warning',
                'message' => 'Dieser Adapter unterstützt keinen Selbsttest. Konfiguration wird beim nächsten Pull geprüft.',
            ]);
        }

        $result = $adapter->selfTest($channel);
        return new JsonResponse([
            'status' => $result->status,
            'message' => $result->message,
            'detail' => $result->detail,
        ]);
    }
}
