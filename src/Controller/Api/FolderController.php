<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Folder;
use App\Security\Voter\WorktidePermission;
use App\Service\FolderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Recursive folder delete (Nextcloud-style — non-empty folders may be deleted):
 *
 *   DELETE /v1/folders/{id}
 *
 * Soft-deletes the folder and its whole subtree (descendant folders + contained
 * files) via {@see FolderService}. There is no API-Platform Delete operation on
 * Folder, so this custom route owns the path. Requires DELETE on the folder
 * (delegated to its target entity by {@see \App\Security\Voter\FolderVoter}).
 */
final class FolderController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly FolderService $folders,
    ) {}

    #[Route(
        path: '/v1/folders/{id}',
        name: 'api_folder_delete',
        requirements: ['id' => Requirement::UUID_V7],
        methods: ['DELETE'],
    )]
    public function delete(string $id): Response
    {
        $folder = $this->em->find(Folder::class, Uuid::fromString($id));
        if (!$folder instanceof Folder || $folder->getDeletedAt() !== null) {
            throw new NotFoundHttpException();
        }
        if (!$this->security->isGranted(WorktidePermission::DELETE, $folder)) {
            throw new AccessDeniedHttpException();
        }

        $this->folders->deleteRecursive($folder);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
