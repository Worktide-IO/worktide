<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\StaffCalendarConnectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A staff member's external calendar connection for booking free/busy. v1 is an
 * ICS feed URL (the secret export URL Google/Outlook/Apple provide) polled by
 * cron; a future upgrade adds Google/Outlook OAuth. Busy times land in
 * {@see CalendarBusyBlock}, which {@see \App\Service\Booking\BookingSlotService}
 * subtracts so a slot is only offered when the host is actually free.
 *
 * One connection per user. Admin CRUD via this workspace-scoped resource
 * (create sends the `workspace` IRI, like Document). The ICS URL is a credential
 * of sorts — it is write-only in the API (never serialized back).
 */
#[ORM\Entity(repositoryClass: StaffCalendarConnectionRepository::class)]
#[ORM\Table(name: 'staff_calendar_connections')]
#[ORM\UniqueConstraint(name: 'staff_calendar_user_uniq', columns: ['owner_id'])]
#[ORM\Index(name: 'staff_calendar_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'StaffCalendarConnection',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(securityPostDenormalize: "is_granted('EDIT', object.getWorkspace())"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'owner' => 'exact'])]
class StaffCalendarConnection
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Write-only in the API — a secret feed URL, never serialized back. */
    #[ORM\Column(length: 1000)]
    private string $icsUrl = '';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastError = null;

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * Write-only: keep the secret feed URL out of API responses. The setter is
     * still exposed so it can be written on create/patch.
     */
    public function setIcsUrl(string $icsUrl): self
    {
        $this->icsUrl = $icsUrl;

        return $this;
    }

    /** Not `getIcsUrl()` — that would serialize the secret. Internal accessor. */
    public function icsUrl(): string
    {
        return $this->icsUrl;
    }

    /** Serialized flag so the UI can show whether a feed is configured. */
    public function isConfigured(): bool
    {
        return $this->icsUrl !== '';
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $active): self
    {
        $this->isActive = $active;

        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeImmutable $at): self
    {
        $this->lastSyncedAt = $at;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $error): self
    {
        $this->lastError = $error === null ? null : mb_substr($error, 0, 500);

        return $this;
    }
}
