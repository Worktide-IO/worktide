<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\FileTarget;
use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\CommentRepository;
use App\Repository\FileRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Security\Voter\WorktidePermission;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Multipart file uploads.
 *
 *   POST /v1/files                      → create new File + version 1
 *   POST /v1/files/{id}/versions        → add a new revision to an existing File
 *
 * Body (multipart/form-data):
 *   file        (binary, required)
 *   target      (string, required for new File): project|task|workspace|user|comment
 *   targetId    (uuid,   required for new File)
 *   name        (string, optional — defaults to the uploaded filename)
 *   description (string, optional)
 *   note        (string, optional — attached to the FileVersion, not the File)
 *
 * Access: requires VIEW on target for new File creation, EDIT on the File for
 * adding a new version. Cross-workspace upload attempts (targetId points to an
 * entity in a different workspace than the authed user) → 403.
 *
 * Pre-signed-URL flow (POST /v1/files/generate-upload-url + PUT to blob) is
 * deferred to a follow-up commit when an S3 adapter is wired.
 */
final class FileUploadController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly FileStorage $storage,
        private readonly FileRepository $files,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly WorkspaceRepository $workspaces,
        private readonly UserRepository $users,
        private readonly CommentRepository $comments,
    ) {}

    #[Route(
        path: '/v1/files',
        name: 'api_file_upload',
        host: 'api.worktide.ddev.site',
        methods: ['POST'],
    )]
    public function upload(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $uploaded = $request->files->get('file');
        if ($uploaded === null) {
            throw new BadRequestHttpException('Multipart field "file" is required.');
        }

        $targetEnum = $this->extractTarget($request);
        $targetId = $this->extractUuid($request, 'targetId');
        $targetEntity = $this->resolveTargetEntity($targetEnum, $targetId);
        if ($targetEntity === null) {
            throw new NotFoundHttpException('Target not found.');
        }
        if (!$this->security->isGranted(WorktidePermission::VIEW, $targetEntity)) {
            throw new AccessDeniedHttpException('Not allowed to attach files to this target.');
        }

        $workspace = $this->resolveWorkspace($targetEnum, $targetEntity);
        if (!$workspace instanceof Workspace) {
            throw new BadRequestHttpException('Could not resolve workspace from target.');
        }

        $originalName = $uploaded->getClientOriginalName() ?: 'file';
        $displayName = (string) ($request->request->get('name') ?? $originalName);
        $description = $request->request->get('description');

        // Persist File + FileVersion in one transaction, computing UUIDs up
        // front so the storage path stays stable.
        $file = (new File())
            ->setWorkspace($workspace)
            ->setTarget($targetEnum)
            ->setTargetId($targetId)
            ->setName($displayName)
            ->setDescription(\is_string($description) ? $description : null)
            ->setMimeType($uploaded->getClientMimeType())
            ->setUploadedBy($user);
        $this->em->persist($file);
        $this->em->flush(); // gives File its UUID

        $version = $this->createVersion($file, 1, $uploaded, $originalName, $user, $request->request->get('note'));
        $file->setCurrentVersion($version);
        $this->em->flush();

        return new JsonResponse($this->summarise($file), JsonResponse::HTTP_CREATED);
    }

    #[Route(
        path: '/v1/files/{id}/versions',
        name: 'api_file_new_version',
        host: 'api.worktide.ddev.site',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['POST'],
    )]
    public function newVersion(string $id, Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $file = $this->files->find(Uuid::fromString($id));
        if (!$file instanceof File) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::EDIT, $file)) {
            throw new AccessDeniedHttpException();
        }

        $uploaded = $request->files->get('file');
        if ($uploaded === null) {
            throw new BadRequestHttpException('Multipart field "file" is required.');
        }

        $nextNumber = ($file->getCurrentVersion()?->getVersionNumber() ?? 0) + 1;
        $originalName = $uploaded->getClientOriginalName() ?: 'file';
        $version = $this->createVersion($file, $nextNumber, $uploaded, $originalName, $user, $request->request->get('note'));

        $file->setCurrentVersion($version);
        $file->setMimeType($uploaded->getClientMimeType());
        $this->em->flush();

        return new JsonResponse($this->summarise($file), JsonResponse::HTTP_CREATED);
    }

    private function createVersion(File $file, int $number, \Symfony\Component\HttpFoundation\File\UploadedFile $upload, string $originalName, User $user, mixed $note): FileVersion
    {
        // Two-step persist: first row with mandatory fields + placeholder
        // storage info so we can flush and get a UUID, then write bytes and
        // update the path/checksum on the same row.
        $version = (new FileVersion())
            ->setFile($file)
            ->setVersionNumber($number)
            ->setOriginalFilename($originalName)
            ->setMimeType($upload->getClientMimeType() ?: 'application/octet-stream')
            ->setChecksum('pending')
            ->setStoragePath('pending')
            ->setUploadedBy($user)
            ->setNote(\is_string($note) ? $note : null);

        $this->em->persist($version);
        $this->em->flush();

        $info = $this->storage->ingestUpload($upload, $file->getWorkspace(), $file->getId(), $version->getId(), $originalName);

        $version
            ->setSize($info['size'])
            ->setChecksum($info['checksum'])
            ->setStoragePath($info['path']);

        return $version;
    }

    private function extractTarget(Request $request): FileTarget
    {
        $raw = (string) $request->request->get('target', '');
        $enum = FileTarget::tryFrom($raw);
        if ($enum === null) {
            throw new BadRequestHttpException('Field "target" must be one of: project, task, workspace, user, comment.');
        }
        return $enum;
    }

    private function extractUuid(Request $request, string $field): Uuid
    {
        $raw = (string) $request->request->get($field, '');
        try {
            return Uuid::fromString($raw);
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be a UUID.', $field));
        }
    }

    private function resolveTargetEntity(FileTarget $target, Uuid $id): ?object
    {
        return match ($target) {
            FileTarget::Project => $this->projects->find($id),
            FileTarget::Task => $this->tasks->find($id),
            FileTarget::Workspace => $this->workspaces->find($id),
            FileTarget::User => $this->users->find($id),
            FileTarget::Comment => $this->comments->find($id),
            FileTarget::Document => null,
        };
    }

    private function resolveWorkspace(FileTarget $target, object $entity): ?Workspace
    {
        if ($entity instanceof Workspace) {
            return $entity;
        }
        if (\method_exists($entity, 'getWorkspace')) {
            $ws = $entity->getWorkspace();
            return $ws instanceof Workspace ? $ws : null;
        }
        if ($entity instanceof User) {
            $first = $entity->getWorkspaceMemberships()->first();
            return $first ? $first->getWorkspace() : null;
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function summarise(File $file): array
    {
        $current = $file->getCurrentVersion();
        return [
            'id' => $file->getId()?->toRfc4122(),
            'name' => $file->getName(),
            'mimeType' => $file->getMimeType(),
            'target' => $file->getTarget()->value,
            'targetId' => $file->getTargetId()->toRfc4122(),
            'size' => $current?->getSize(),
            'currentVersion' => $current ? [
                'id' => $current->getId()?->toRfc4122(),
                'number' => $current->getVersionNumber(),
                'checksum' => $current->getChecksum(),
                'originalFilename' => $current->getOriginalFilename(),
            ] : null,
            'downloadUrl' => sprintf('/v1/files/%s/content', $file->getId()?->toRfc4122() ?? ''),
        ];
    }
}
