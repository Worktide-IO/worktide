<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AppVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public build/version endpoint (under /v1/version, see security.yaml).
 *
 * Lets operators and both SPAs see which build is actually live — the fastest
 * way to answer "is my fix deployed?" and to spot a stale SPA talking to a
 * newer API. Non-sensitive; unauthenticated like /v1/branding.
 */
final readonly class VersionController
{
    public function __construct(
        private AppVersion $version,
    ) {}

    #[Route(path: '/v1/version', name: 'api_version', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        // No cache: it must reflect the currently-running build immediately
        // after a deploy (caching would defeat the whole point).
        $response = new JsonResponse($this->version->toArray());
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
