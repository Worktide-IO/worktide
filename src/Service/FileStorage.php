<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\Workspace;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Thin abstraction over Flysystem for file storage.
 *
 * Two responsibilities:
 *  1. Compute the canonical storage path for a FileVersion (so callers don't
 *     have to repeat the convention) — currently `{workspace}/{file}/{vid}-{name}`.
 *  2. Stream bytes in and out of the backing filesystem.
 *
 * Stays a vanilla service so swapping the underlying adapter (local → S3 →
 * GCS) only touches services.yaml, not callers.
 */
final class FileStorage
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {}

    /**
     * Move an uploaded multipart file into storage, returning the path under
     * which it now lives and the SHA-256 checksum + final byte size.
     *
     * @return array{path: string, size: int, checksum: string}
     */
    public function ingestUpload(UploadedFile $upload, Workspace $workspace, Uuid $fileId, Uuid $versionId, string $originalName): array
    {
        $safeName = $this->sanitiseFilename($originalName);
        $path = sprintf(
            '%s/%s/%s-%s',
            $workspace->getId()?->toRfc4122() ?? 'unknown',
            $fileId->toRfc4122(),
            $versionId->toRfc4122(),
            $safeName,
        );

        $stream = fopen($upload->getRealPath(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open uploaded file for reading.');
        }
        try {
            $this->filesystem->writeStream($path, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'path' => $path,
            'size' => (int) $upload->getSize(),
            'checksum' => hash_file('sha256', $upload->getRealPath()) ?: '',
        ];
    }

    /**
     * Persist raw bytes (e.g. seeded fixtures) — same path convention as
     * ingestUpload. Returns the same metadata triple.
     *
     * @return array{path: string, size: int, checksum: string}
     */
    public function writeBytes(string $bytes, Workspace $workspace, Uuid $fileId, Uuid $versionId, string $originalName): array
    {
        $safeName = $this->sanitiseFilename($originalName);
        $path = sprintf(
            '%s/%s/%s-%s',
            $workspace->getId()?->toRfc4122() ?? 'unknown',
            $fileId->toRfc4122(),
            $versionId->toRfc4122(),
            $safeName,
        );
        $this->filesystem->write($path, $bytes);

        return [
            'path' => $path,
            'size' => \strlen($bytes),
            'checksum' => hash('sha256', $bytes),
        ];
    }

    /**
     * Returns a read-only stream resource for the given version. Caller is
     * responsible for closing it.
     *
     * @return resource
     */
    public function readStream(FileVersion $version)
    {
        return $this->filesystem->readStream($version->getStoragePath());
    }

    /**
     * Path-based read for callers that hold a raw storage path string
     * (e.g. mail attachments stored under inbound_events.attachments[].storedAt
     * — no File entity row).
     *
     * @return resource|null  null if the path is missing or unreadable
     */
    public function readStreamByPath(string $path)
    {
        try {
            if (!$this->filesystem->fileExists($path)) {
                return null;
            }
            return $this->filesystem->readStream($path);
        } catch (FilesystemException) {
            return null;
        }
    }

    public function exists(FileVersion $version): bool
    {
        try {
            return $this->filesystem->fileExists($version->getStoragePath());
        } catch (FilesystemException) {
            return false;
        }
    }

    public function delete(FileVersion $version): void
    {
        try {
            $this->filesystem->delete($version->getStoragePath());
        } catch (FilesystemException) {
            // best-effort; the row is gone either way
        }
    }

    private function sanitiseFilename(string $name): string
    {
        // Strip path separators + control chars + leading dots; keep unicode.
        $name = preg_replace('#[\x00-\x1F\x7F/\\\\]+#', '_', $name) ?? '';
        $name = ltrim($name, '.');
        $name = mb_substr($name, 0, 200);
        return $name === '' ? 'file' : $name;
    }
}
