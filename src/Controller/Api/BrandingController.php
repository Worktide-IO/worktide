<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Branding\BrandingConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public branding endpoint (under /v1/branding, see security.yaml).
 *
 * Both SPAs (worktide-web, worktide-portal) fetch this at runtime to apply the
 * instance's logo, brand colors and legal links — so an operator can rebrand by
 * setting BRAND_* env vars alone, without rebuilding any frontend. Values mirror
 * what the system emails use via the `brand` Twig global.
 *
 * Intentionally unauthenticated: branding must be available on the login and
 * set-password pages, and it contains no sensitive data.
 */
final readonly class BrandingController
{
    public function __construct(
        private BrandingConfig $branding,
    ) {}

    #[Route(path: '/v1/branding', name: 'api_branding', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse($this->branding->toArray());

        // Short public cache — branding changes rarely and is non-sensitive.
        return $response->setPublic()->setMaxAge(300);
    }
}
