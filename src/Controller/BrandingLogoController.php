<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the instance logo used as the default when BRAND_LOGO_URL is unset.
 *
 * Resolution order:
 *   1. a file mounted at var/branding/logo.{svg,png,…} (drop-in, no rebuild —
 *      var/ is a persistent volume in the self-hosted stack), otherwise
 *   2. the bundled Worktide logo shipped in the repo.
 *
 * Public (route is outside /v1, so not covered by the API firewall's ROLE_USER
 * default) — a logo is not sensitive and must load on unauthenticated pages and
 * in emails.
 */
final class BrandingLogoController
{
    /**
     * Basenames probed under var/branding/, in order, mapped to their MIME type.
     *
     * @var array<string, string>
     */
    private const array CANDIDATES = [
        'logo.svg' => 'image/svg+xml',
        'logo.png' => 'image/png',
        'logo.webp' => 'image/webp',
        'logo.jpg' => 'image/jpeg',
        'logo.jpeg' => 'image/jpeg',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    #[Route(path: '/branding/logo', name: 'branding_logo', methods: ['GET'])]
    public function __invoke(): Response
    {
        [$path, $mime] = $this->resolveLogo();

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $mime);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /** @return array{0: string, 1: string} [absolutePath, mimeType] */
    private function resolveLogo(): array
    {
        $mountDir = $this->projectDir . '/var/branding/';
        foreach (self::CANDIDATES as $basename => $mime) {
            $candidate = $mountDir . $basename;
            if (is_file($candidate)) {
                return [$candidate, $mime];
            }
        }

        // Bundled fallback shipped with the repo.
        return [$this->projectDir . '/logo.svg', 'image/svg+xml'];
    }
}
