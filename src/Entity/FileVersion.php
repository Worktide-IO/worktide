<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\FileVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One revision of a File. The actual bytes live on Flysystem at `storagePath`;
 * this row holds the metadata needed to (a) verify integrity (checksum), (b)
 * display in the UI (size, mimeType, originalFilename) and (c) reconstruct
 * the storage key on download.
 *
 * Storage path convention: `{workspace_id}/{file_id}/{version_id}-{filename}`
 * — flat enough that S3 hosting works without prefixing rules, but still
 * groups all versions of one file together in browsers.
 */
#[ORM\Entity(repositoryClass: FileVersionRepository::class)]
#[ORM\Table(name: 'file_versions')]
#[ORM\UniqueConstraint(name: 'file_version_number_unique', columns: ['file_id', 'version_number'])]
#[ORM\Index(name: 'file_version_file_idx', columns: ['file_id'])]
#[ORM\HasLifecycleCallbacks]
class FileVersion
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private File $file;

    #[ORM\Column(type: 'integer')]
    private int $versionNumber = 1;

    /** Filename as it was uploaded (UTF-8). */
    #[ORM\Column(length: 255)]
    private string $originalFilename;

    /** Bytes. */
    #[ORM\Column(type: 'bigint')]
    private string $size = '0';

    #[ORM\Column(length: 120)]
    private string $mimeType = 'application/octet-stream';

    /** SHA-256 hex of the bytes — integrity + dedup. */
    #[ORM\Column(length: 64)]
    private string $checksum;

    /** Flysystem path relative to the configured storage root. */
    #[ORM\Column(length: 500)]
    private string $storagePath;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    public function getFile(): File { return $this->file; }
    public function setFile(File $file): self { $this->file = $file; return $this; }

    public function getVersionNumber(): int { return $this->versionNumber; }
    public function setVersionNumber(int $n): self { $this->versionNumber = $n; return $this; }

    public function getOriginalFilename(): string { return $this->originalFilename; }
    public function setOriginalFilename(string $name): self { $this->originalFilename = $name; return $this; }

    public function getSize(): int { return (int) $this->size; }
    public function setSize(int $size): self { $this->size = (string) $size; return $this; }

    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mime): self { $this->mimeType = $mime; return $this; }

    public function getChecksum(): string { return $this->checksum; }
    public function setChecksum(string $hash): self { $this->checksum = $hash; return $this; }

    public function getStoragePath(): string { return $this->storagePath; }
    public function setStoragePath(string $path): self { $this->storagePath = $path; return $this; }

    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function setUploadedBy(?User $user): self { $this->uploadedBy = $user; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note; return $this; }
}
