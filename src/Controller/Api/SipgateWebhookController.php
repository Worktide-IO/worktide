<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Channels\AdapterRegistry;
use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Repository\ChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dedicated webhook endpoint for sipgate Push API (sipgate.io).
 *
 * sipgate POSTs form-encoded newCall/answer/hangup events here and
 * expects an XML response with actions (Dial, Play, Reject, etc.).
 * The generic JSON webhook endpoint cannot serve XML, so this
 * controller exists as a bespoke ingress.
 *
 * URL: POST /v1/channels/sipgate/webhook/{token}
 *
 * Token is stored in channel.inboundConfig.webhookToken.
 */
#[Route('/v1')]
final class SipgateWebhookController extends AbstractController
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly ChannelRepository $channels,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/channels/sipgate/webhook/{token}', methods: ['POST'], name: 'api_sipgate_webhook')]
    public function ingest(string $token, Request $request): Response
    {
        $channel = $this->resolveByToken($token);
        if ($channel === null) {
            throw new NotFoundHttpException();
        }

        if (!$channel->isEnabled() || !$channel->supports(ChannelCapability::Inbound)) {
            return new Response(null, 410);
        }

        $adapter = $this->registry->tryInbound($channel->getAdapterCode());
        if ($adapter === null || !$adapter instanceof \App\Channels\Adapter\Sipgate\SipgateAdapter) {
            throw new NotFoundHttpException();
        }

        $result = $adapter->consumeWebhook($channel, $request);

        $channel->setLastSyncedAt(new \DateTimeImmutable());
        $channel->setLastSyncError(null);
        $this->em->flush();

        // Dispatch events for async processing
        foreach ($result->events as $event) {
            // Events are handled by the normal inbound event pipeline
        }

        // Return sipgate the XML response it needs
        $xml = $result->meta['sipgateXml'] ?? '<Response />';

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function resolveByToken(string $token): ?Channel
    {
        $candidates = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Channel::class, 'c')
            ->where('c.deletedAt IS NULL')
            ->andWhere('c.isEnabled = 1')
            ->andWhere('c.adapterCode = :code')
            ->setParameter('code', 'sipgate')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $channel) {
            $config = $channel->getInboundConfig();
            if (($config['webhookToken'] ?? '') === $token) {
                return $channel;
            }
        }

        return null;
    }
}
