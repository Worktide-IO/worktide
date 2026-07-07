<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Enum\FileTarget;
use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\FileStorage;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Ticket attachments for the customer portal (wireframe screen 2, "📎 Anhang").
 *
 * A ticket attachment is a {@see File} with target=Task + targetId=ticket, and
 * is always created visible to the customer (isHiddenForConnectUsers=false).
 * Both endpoints authorize the ticket through {@see PortalAccessResolver}
 * (identity chain + hidden-ticket gate) and never expose the internal file API.
 * Reuses {@see FileStorage} (Flysystem) for the bytes; the read path only ever
 * serves attachments that belong to the ticket and are not hidden.
 */
final class PortalAttachmentsController
{
    /** Portal upload cap (bytes). Keeps untrusted uploads bounded. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly FileRepository $files,
        private readonly FileStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    #[Route(
        path: '/v1/portal/tickets/{id}/attachments',
        name: 'api_portal_ticket_attachment_upload',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function upload(string $id, Request $request): JsonResponse
    {
        $this->portal->assertPortalEnabled();
        $task = $this->portal->findTicketOr404(Uuid::fromString($id));

        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile) {
            throw new BadRequestHttpException('Multipart field "file" is required.');
        }
        if ($uploaded->getSize() !== null && $uploaded->getSize() > self::MAX_BYTES) {
            throw new BadRequestHttpException('Datei ist zu groß (max. 10 MB).');
        }

        $originalName = $uploaded->getClientOriginalName() ?: 'anhang';
        $user = $this->portalUser();

        $file = (new File())
            ->setWorkspace($task->getWorkspace())
            ->setTarget(FileTarget::Task)
            ->setTargetId($task->getId())
            ->setName($originalName)
            ->setMimeType($uploaded->getClientMimeType())
            ->setUploadedBy($user);
        // Customer-uploaded attachments are always visible to the customer.
        $file->setIsHiddenForConnectUsers(false);
        $this->em->persist($file);
        $this->em->flush(); // mint File UUID

        // Two-step persist mirrors FileUploadController::createVersion.
        $version = (new FileVersion())
            ->setFile($file)
            ->setVersionNumber(1)
            ->setOriginalFilename($originalName)
            ->setMimeType($uploaded->getClientMimeType() ?: 'application/octet-stream')
            ->setChecksum('pending')
            ->setStoragePath('pending')
            ->setUploadedBy($user);
        $this->em->persist($version);
        $this->em->flush(); // mint FileVersion UUID

        $info = $this->storage->ingestUpload($uploaded, $task->getWorkspace(), $file->getId(), $version->getId(), $originalName);
        $version->setSize($info['size'])->setChecksum($info['checksum'])->setStoragePath($info['path']);
        $file->setCurrentVersion($version);
        $this->em->flush();

        return new JsonResponse($this->attachmentDto($file), 201);
    }

    #[Route(
        path: '/v1/portal/tickets/{id}/attachments/{fileId}/content',
        name: 'api_portal_ticket_attachment_download',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'fileId' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function download(string $id, string $fileId, Request $request): Response
    {
        $this->portal->assertPortalEnabled();
        $task = $this->portal->findTicketOr404(Uuid::fromString($id));

        $file = $this->files->findVisibleTaskAttachment($task, Uuid::fromString($fileId));
        $version = $file?->getCurrentVersion();
        if ($file === null || !$version instanceof FileVersion) {
            throw new NotFoundHttpException();
        }

        $etag = '"' . $version->getChecksum() . '"';
        if (\in_array($etag, $request->getETags(), true)) {
            return new Response('', Response::HTTP_NOT_MODIFIED, ['ETag' => $etag]);
        }
        if (!$this->storage->exists($version)) {
            throw new NotFoundHttpException('Stored content missing.');
        }

        $response = new StreamedResponse(function () use ($version): void {
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
        $response->headers->set('Content-Type', $version->getMimeType() ?: 'application/octet-stream');
        $response->headers->set('ETag', $etag);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                $request->query->getBoolean('inline') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $file->getName(),
            ),
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function attachmentDto(File $file): array
    {
        return [
            'id' => $file->getId()?->toRfc4122(),
            'name' => $file->getName(),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploadedAt' => $file->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function portalUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        return $user;
    }
}
