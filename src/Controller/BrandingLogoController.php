<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the instance's branding images (logo, dark-mode logo, favicon) as a
 * drop-in: files placed under var/branding/ override the built-in defaults with
 * no rebuild — var/ is a persistent, per-instance volume in the self-hosted
 * stack (and gitignored, so it never lands in the shared product repo).
 *
 * For each asset the first matching basename under var/branding/ is served:
 *   logo       → logo.{svg,png,webp,jpg,jpeg}
 *   logo-dark  → logo-dark.{svg,png,webp,jpg,jpeg}
 *   favicon    → favicon.{svg,png,ico,webp}
 * When none is present the route 404s; the SPAs then fall back to their bundled
 * asset (see BrandLogo's FALLBACK_LOGO / the bundled favicon), so an unbranded
 * instance still renders.
 *
 * Public (routes are outside /v1, so not covered by the API firewall's
 * ROLE_USER default) — branding images are not sensitive and must load on
 * unauthenticated pages and in emails.
 *
 * NB: the body is returned as a plain Response, not a BinaryFileResponse.
 * Symfony's HttpCache store cannot capture a BinaryFileResponse body (its
 * getContent() is false), so behind the app's HttpCache the file streamed as an
 * empty, then-cached 200 — a normal Response is stored and replayed correctly.
 */
final class BrandingLogoController
{
    /**
     * MIME type per file extension, in probe order for each asset.
     *
     * @var array<string, string>
     */
    private const array MIME = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'ico' => 'image/x-icon',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    #[Route(path: '/branding/logo', name: 'branding_logo', methods: ['GET'])]
    public function logo(): Response
    {
        return $this->serve('logo', ['svg', 'png', 'webp', 'jpg', 'jpeg']);
    }

    #[Route(path: '/branding/logo-dark', name: 'branding_logo_dark', methods: ['GET'])]
    public function logoDark(): Response
    {
        return $this->serve('logo-dark', ['svg', 'png', 'webp', 'jpg', 'jpeg']);
    }

    #[Route(path: '/branding/favicon', name: 'branding_favicon', methods: ['GET'])]
    public function favicon(): Response
    {
        return $this->serve('favicon', ['svg', 'png', 'ico', 'webp']);
    }

    /**
     * @param list<string> $extensions extensions to probe for $basename, in order
     */
    private function serve(string $basename, array $extensions): Response
    {
        $dir = $this->projectDir . '/var/branding/';
        foreach ($extensions as $ext) {
            $candidate = $dir . $basename . '.' . $ext;
            if (is_file($candidate)) {
                $body = file_get_contents($candidate);
                if ($body === false) {
                    break;
                }

                $response = new Response($body, Response::HTTP_OK, [
                    'Content-Type' => self::MIME[$ext],
                ]);
                $response->setPublic();
                $response->setMaxAge(3600);

                return $response;
            }
        }

        // No drop-in file → let the SPA use its bundled fallback.
        return new Response('', Response::HTTP_NOT_FOUND);
    }
}
