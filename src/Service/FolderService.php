<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\File;
use App\Entity\Folder;
use App\Repository\FileRepository;
use App\Repository\FolderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Folder-tree operations that span more than one row. Currently: recursive
 * (non-empty) delete — Nextcloud lets you delete a folder with contents, so we
 * soft-delete the whole subtree (descendant folders + their files) depth-first.
 *
 * Soft-delete only (matching the rest of the app); blob cleanup is a separate,
 * still-unsolved concern. The caller flushes.
 */
final class FolderService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FolderRepository $folders,
        private readonly FileRepository $files,
    ) {}

    /**
     * Soft-delete $folder and everything beneath it. Does not flush.
     */
    public function deleteRecursive(Folder $folder): void
    {
        foreach ($this->folders->findChildren($folder) as $child) {
            $this->deleteRecursive($child);
        }
        /** @var list<File> $contained */
        $contained = $this->files->findBy(['folder' => $folder]);
        foreach ($contained as $file) {
            $file->softDelete();
        }
        $folder->softDelete();
        // Keep the UoW aware even if the caller only flushes once at the end.
        $this->em->persist($folder);
    }
}
