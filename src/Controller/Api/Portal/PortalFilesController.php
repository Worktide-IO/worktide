<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Customer;
use App\Entity\Enum\FileTarget;
use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\Folder;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\FolderRepository;
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
 * Customer file area for the portal (the "shared with the customer" space).
 *
 * The whole area is scoped to the authenticated contact's customer via
 * {@see PortalAccessResolver}. TENANT ISOLATION is the load-bearing property
 * here: the customer is taken ONLY from the resolver, never from the request,
 * and every query goes through the customer-scoped repository methods
 * ({@see FileRepository::findVisibleFilesForCustomer} etc.) that hard-filter on
 * target=Customer + the resolver's customer id + not-hidden + not-deleted. Any
 * folder/file id that doesn't belong to the caller's customer resolves to 404,
 * so a contact can neither see nor enumerate another customer's files.
 *
 * Portal contacts may browse, download and upload (into staff-created folders);
 * folder management stays staff-only for now.
 */
final class PortalFilesController
{
    /** Portal upload cap (bytes). Keeps untrusted uploads bounded. */
    private const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly FileRepository $files,
        private readonly FolderRepository $folders,
        private readonly FileStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    #[Route(path: '/v1/portal/files', name: 'api_portal_files_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('files');
        $customer = $this->portal->customer();

        $folder = $this->resolveFolder($customer, $request->query->get('folder'));

        $folders = $this->folders->findVisibleChildrenForCustomer($customer, $folder);
        $files = $this->files->findVisibleFilesForCustomer($customer, $folder);

        return new JsonResponse([
            'folder' => $folder !== null ? $this->folderDto($folder) : null,
            'folders' => array_map($this->folderDto(...), $folders),
            'files' => array_map($this->fileDto(...), $files),
        ]);
    }

    #[Route(
        path: '/v1/portal/files/{id}/content',
        name: 'api_portal_files_download',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function download(string $id, Request $request): Response
    {
        $this->portal->assertFeatureEnabled('files');
        $customer = $this->portal->customer();

        $file = $this->files->findVisibleCustomerFile($customer, Uuid::fromString($id));
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

    #[Route(path: '/v1/portal/files', name: 'api_portal_files_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $this->portal->assertFeatureEnabled('files');
        $customer = $this->portal->customer();

        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile) {
            throw new BadRequestHttpException('Multipart field "file" is required.');
        }
        if ($uploaded->getSize() !== null && $uploaded->getSize() > self::MAX_BYTES) {
            throw new BadRequestHttpException('Datei ist zu groß (max. 50 MB).');
        }

        $folder = $this->resolveFolder($customer, $request->request->get('parent'));
        $originalName = $uploaded->getClientOriginalName() ?: 'datei';
        $user = $this->portalUser();

        $file = (new File())
            ->setWorkspace($customer->getWorkspace())
            ->setTarget(FileTarget::Customer)
            ->setTargetId($customer->getId())
            ->setFolder($folder)
            ->setName($originalName)
            ->setMimeType($uploaded->getClientMimeType())
            ->setUploadedBy($user)
            ->setIsHiddenForConnectUsers(false);
        $this->em->persist($file);
        $this->em->flush(); // mint File UUID

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

        $info = $this->storage->ingestUpload($uploaded, $customer->getWorkspace(), $file->getId(), $version->getId(), $originalName);
        $version->setSize($info['size'])->setChecksum($info['checksum'])->setStoragePath($info['path']);
        $file->setCurrentVersion($version);
        $this->em->flush();

        return new JsonResponse($this->fileDto($file), 201);
    }

    /**
     * Resolve an optional folder reference (UUID or folder IRI) to a Folder that
     * PROVABLY belongs to the caller's customer, or null for the root. Anything
     * not owned by the customer → 404 (no cross-customer enumeration).
     */
    private function resolveFolder(Customer $customer, mixed $ref): ?Folder
    {
        if (!\is_string($ref) || $ref === '') {
            return null;
        }
        $raw = str_contains($ref, '/') ? (string) substr((string) strrchr($ref, '/'), 1) : $ref;
        try {
            $uuid = Uuid::fromString($raw);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        }
        $folder = $this->folders->findVisibleCustomerFolder($customer, $uuid);
        if ($folder === null) {
            throw new NotFoundHttpException();
        }

        return $folder;
    }

    /**
     * @return array<string, mixed>
     */
    private function folderDto(Folder $folder): array
    {
        return [
            'id' => $folder->getId()?->toRfc4122(),
            'name' => $folder->getName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileDto(File $file): array
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
