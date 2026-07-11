<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\FileTarget;
use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\User;
use App\Entity\WorkspaceMember;
use App\Repository\FileRepository;
use App\Security\Voter\WorktidePermission;
use App\Service\FileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Member avatars, gated on the workspace instead of on the target User.
 *
 *   POST /v1/workspace_members/{id}/avatar   (multipart `file`, MANAGE)
 *   GET  /v1/workspace_members/{id}/avatar   (workspace VIEW) → image bytes
 *
 * The avatar is stored as a normal File (target=user), but managing/serving it
 * goes through the membership so a workspace manager can set a colleague's photo
 * and any member can see it — the generic File endpoints gate on VIEW of the
 * target User, which co-members don't hold.
 */
final class WorkspaceMemberAvatarController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly FileStorage $storage,
        private readonly FileRepository $files,
    ) {}

    #[Route(
        path: '/v1/workspace_members/{id}/avatar',
        name: 'api_workspace_member_avatar_set',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function set(string $id, Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $member = $this->member($id);
        if (!$this->security->isGranted('MANAGE', $member->getWorkspace())) {
            throw new AccessDeniedHttpException('You cannot manage this workspace.');
        }

        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile) {
            throw new BadRequestHttpException('Multipart field "file" is required.');
        }
        if (!str_starts_with((string) $uploaded->getClientMimeType(), 'image/')) {
            throw new BadRequestHttpException('Avatar must be an image.');
        }

        $target = $member->getUser();
        $originalName = $uploaded->getClientOriginalName() ?: 'avatar';

        $file = (new File())
            ->setWorkspace($member->getWorkspace())
            ->setTarget(FileTarget::User)
            ->setTargetId($target->getId())
            ->setName('avatar')
            ->setMimeType($uploaded->getClientMimeType())
            ->setUploadedBy($user);
        $this->em->persist($file);
        $this->em->flush(); // assigns the File UUID

        $version = (new FileVersion())
            ->setFile($file)
            ->setVersionNumber(1)
            ->setOriginalFilename($originalName)
            ->setMimeType($uploaded->getClientMimeType() ?: 'application/octet-stream')
            ->setChecksum('pending')
            ->setStoragePath('pending')
            ->setUploadedBy($user);
        $this->em->persist($version);
        $this->em->flush();

        $info = $this->storage->ingestUpload($uploaded, $file->getWorkspace(), $file->getId(), $version->getId(), $originalName);
        $version->setSize($info['size'])->setChecksum($info['checksum'])->setStoragePath($info['path']);
        $file->setCurrentVersion($version);
        $this->em->flush();

        return new JsonResponse(['fileId' => $file->getId()?->toRfc4122()], JsonResponse::HTTP_CREATED);
    }

    #[Route(
        path: '/v1/workspace_members/{id}/avatar',
        name: 'api_workspace_member_avatar_get',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['GET'],
    )]
    public function get(string $id): StreamedResponse
    {
        $this->currentUser();
        $member = $this->member($id);
        if (!$this->security->isGranted(WorktidePermission::VIEW, $member->getWorkspace())) {
            throw new AccessDeniedHttpException('You are not a member of this workspace.');
        }

        $file = $this->files->findOneBy(
            ['target' => FileTarget::User, 'targetId' => $member->getUser()->getId()],
            ['createdAt' => 'DESC'],
        );
        $version = $file?->getCurrentVersion();
        if ($file === null || $version === null || !$this->storage->exists($version)) {
            throw new NotFoundHttpException('No avatar set.');
        }

        $response = new StreamedResponse(function () use ($version): void {
            $stream = $this->storage->readStream($version);
            while (!feof($stream)) {
                echo fread($stream, 8192);
            }
            fclose($stream);
        });
        $response->headers->set('Content-Type', $version->getMimeType() ?? 'application/octet-stream');
        $response->headers->set('Cache-Control', 'private, max-age=60');

        return $response;
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        return $user;
    }

    private function member(string $id): WorkspaceMember
    {
        try {
            $member = $this->em->find(WorkspaceMember::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            throw new BadRequestHttpException('Invalid member id.');
        }
        if ($member === null) {
            throw new NotFoundHttpException('Member not found.');
        }

        return $member;
    }
}
