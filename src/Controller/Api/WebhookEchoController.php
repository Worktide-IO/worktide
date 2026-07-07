<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only sink for webhook deliveries — POSTs land here, get appended to
 * var/log/webhook-echo.log as one JSON line per call, and the same payload
 * is returned with HTTP 200 so the sender records "success".
 *
 * Useful for end-to-end testing of the dispatcher without an external service.
 * Production deployments should NOT route external traffic to this endpoint
 * (it lives under the api host and is unauthenticated by design — see
 * security.yaml access_control if you need to lock it down).
 *
 * The signature header is also captured in the log so consumers can validate
 * their HMAC implementation without standing up an inspector.
 */
final class WebhookEchoController
{
    public function __construct(
        private readonly string $projectDir,
    ) {}

    #[Route(
        path: '/v1/_webhook-echo',
        name: 'api_webhook_echo',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $line = json_encode([
            'receivedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'signature' => $request->headers->get('X-Worktide-Signature'),
            'event' => $request->headers->get('X-Worktide-Event'),
            'deliveryId' => $request->headers->get('X-Worktide-Delivery'),
            'contentType' => $request->headers->get('Content-Type'),
            'body' => json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $fs = new Filesystem();
        $fs->mkdir($this->projectDir . '/var/log');
        $fs->appendToFile($this->projectDir . '/var/log/webhook-echo.log', $line . "\n");

        return new JsonResponse(['ok' => true]);
    }
}
