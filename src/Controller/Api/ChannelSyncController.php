<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Channel;
use App\Entity\User;
use App\Message\RunChannelSyncMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * POST /v1/channels/{id}/sync
 *
 * Operator-triggered "sync now": enqueues a {@see RunChannelSyncMessage} so the
 * pull runs off the request thread (a Lexoffice/Awork import can take minutes).
 * Returns 202 immediately; the SPA polls the channel's lastSyncedAt/lastSyncError
 * for the outcome.
 *
 * Gated on EDIT (same as channel writes → workspace admin for shared channels),
 * because a sync mutates workspace data.
 */
final class ChannelSyncController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    #[Route(
        path: '/v1/channels/{id}/sync',
        name: 'api_channels_sync',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['POST'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        if (!$this->security->getUser() instanceof User) {
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
        if (!$this->security->isGranted('EDIT', $channel)) {
            throw new AccessDeniedHttpException();
        }
        if (!$channel->isEnabled()) {
            throw new BadRequestHttpException('Channel is disabled — enable it before syncing.');
        }

        $this->bus->dispatch(new RunChannelSyncMessage($channel->getId()));

        return new JsonResponse(['status' => 'queued', 'channelId' => (string) $channel->getId()], JsonResponse::HTTP_ACCEPTED);
    }
}
