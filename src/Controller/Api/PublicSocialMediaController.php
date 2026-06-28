<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\FileStorage;
use App\Service\Social\SocialMediaUrlSigner;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated media stream for outbound social publishing.
 *
 *   GET /v1/social/media/{token}
 *
 * The signed {@see SocialMediaUrlSigner} token IS the credential (like the
 * inbound-webhook + invitation routes) — needed because Instagram's Graph API
 * fetches `image_url` server-side without our JWT. Tokens are short-lived, so
 * the file is only briefly reachable and only to whoever holds the URL.
 * Whitelisted in security.yaml under ^/v1/social/media/.
 */
final class PublicSocialMediaController
{
    public function __construct(
        private readonly SocialMediaUrlSigner $signer,
        private readonly FileRepository $files,
        private readonly FileStorage $storage,
    ) {}

    #[Route(
        path: '/v1/social/media/{token}',
        name: 'api_social_public_media',
        host: 'api.worktide.ddev.site',
        requirements: ['token' => '[^/]+'],
        methods: ['GET'],
    )]
    public function __invoke(string $token): Response
    {
        $fileId = $this->signer->verify($token);
        if ($fileId === null) {
            throw new NotFoundHttpException(); // expired or tampered — don't leak which
        }
        $file = $this->files->find($fileId);
        if (!$file instanceof File) {
            throw new NotFoundHttpException();
        }
        $version = $file->getCurrentVersion();
        if ($version === null || !$this->storage->exists($version)) {
            throw new NotFoundHttpException();
        }

        $response = new StreamedResponse(function () use ($version) {
            $stream = $this->storage->readStream($version);
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            try {
                stream_copy_to_stream($stream, $output);
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
                fclose($output);
            }
        });
        $response->headers->set('Content-Type', $version->getMimeType());
        $response->headers->set('Content-Length', (string) $version->getSize());
        $response->headers->set('Cache-Control', 'private, max-age=600');

        return $response;
    }
}
