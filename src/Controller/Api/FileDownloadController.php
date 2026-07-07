<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\File;
use App\Entity\FileVersion;
use App\Repository\FileRepository;
use App\Repository\FileVersionRepository;
use App\Security\Voter\WorktidePermission;
use App\Service\FileStorage;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Streams the bytes of a stored file.
 *
 *   GET /v1/files/{id}/content                       — current version
 *   GET /v1/files/{id}/versions/{versionId}/content  — specific version
 *
 * Response: streamed; sets Content-Type from the stored mime, Content-Length
 * from the version size, Content-Disposition=attachment with the original
 * filename, and ETag = sha256 checksum so HTTP If-None-Match short-circuits.
 *
 * Inline preview can be requested via `?inline=1` — sets disposition=inline
 * instead of attachment.
 */
final class FileDownloadController
{
    public function __construct(
        private readonly Security $security,
        private readonly FileRepository $files,
        private readonly FileVersionRepository $versions,
        private readonly FileStorage $storage,
    ) {}

    #[Route(
        path: '/v1/files/{id}/content',
        name: 'api_file_content',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['GET'],
    )]
    public function current(string $id, Request $request): Response
    {
        $file = $this->files->find(Uuid::fromString($id));
        if (!$file instanceof File) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $file)) {
            throw new AccessDeniedHttpException();
        }
        $version = $file->getCurrentVersion();
        if ($version === null) {
            throw new NotFoundHttpException('No content for this file.');
        }
        return $this->stream($file, $version, $request);
    }

    #[Route(
        path: '/v1/files/{id}/versions/{versionId}/content',
        name: 'api_file_version_content',
        requirements: ['id' => Requirement::UUID_V7, 'versionId' => Requirement::UUID_V7],
        methods: ['GET'],
    )]
    public function specificVersion(string $id, string $versionId, Request $request): Response
    {
        $file = $this->files->find(Uuid::fromString($id));
        if (!$file instanceof File) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $file)) {
            throw new AccessDeniedHttpException();
        }
        $version = $this->versions->find(Uuid::fromString($versionId));
        if (!$version instanceof FileVersion || $version->getFile()->getId() != $file->getId()) {
            throw new NotFoundHttpException();
        }
        return $this->stream($file, $version, $request);
    }

    private function stream(File $file, FileVersion $version, Request $request): Response
    {
        // ETag short-circuit.
        $etag = $version->getChecksum();
        if ($request->getETags() && \in_array('"' . $etag . '"', $request->getETags(), true)) {
            return new Response('', Response::HTTP_NOT_MODIFIED, ['ETag' => '"' . $etag . '"']);
        }

        if (!$this->storage->exists($version)) {
            throw new NotFoundHttpException('Stored content missing — version exists but blob is gone.');
        }

        $disposition = $request->query->getBoolean('inline')
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

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
        $response->headers->set('ETag', '"' . $etag . '"');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition($disposition, $version->getOriginalFilename()),
        );
        return $response;
    }
}
