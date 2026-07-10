<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\BookingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One booked appointment against a {@see MeetingType}. Created ONLY through the
 * public booking controller (createdByUser stays null); staff read + cancel it
 * via the API Platform resource (no Post here). `startAt`/`endAt` are stored in
 * UTC; the invitee's original timezone is kept for display.
 *
 * `status` (confirmed|cancelled) is used instead of soft-delete so a cancelled
 * slot frees up for re-booking without leaving a tombstone the slot engine has
 * to special-case.
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'bookings')]
#[ORM\Index(name: 'booking_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'booking_type_start_idx', columns: ['meeting_type_id', 'start_at'])]
#[ORM\UniqueConstraint(name: 'booking_cancel_token_uniq', columns: ['cancel_token'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Booking',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'meetingType' => 'exact', 'status' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['startAt'])]
#[ApiFilter(OrderFilter::class, properties: ['startAt'])]
class Booking
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MeetingType $meetingType;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(length: 200)]
    private string $inviteeName = '';

    #[ORM\Column(length: 255)]
    private string $inviteeEmail = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $inviteeTimezone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_CONFIRMED;

    /** Opaque token that lets the invitee cancel without an account. */
    #[ORM\Column(length: 64)]
    private string $cancelToken = '';

    public function getMeetingType(): MeetingType
    {
        return $this->meetingType;
    }

    public function setMeetingType(MeetingType $meetingType): self
    {
        $this->meetingType = $meetingType;
        // A booking lives in its meeting type's workspace.
        $this->setWorkspace($meetingType->getWorkspace());

        return $this;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): self
    {
        // Store in the app default timezone: this codebase persists naive
        // datetimes (Doctrine hydrates them back in the default tz), so a value
        // in any other tz would round-trip to the wrong instant. setTimezone
        // preserves the absolute instant, only the stored wall-clock changes.
        $this->startAt = $startAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $this;
    }

    public function getEndAt(): \DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): self
    {
        $this->endAt = $endAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return $this;
    }

    public function getInviteeName(): string
    {
        return $this->inviteeName;
    }

    public function setInviteeName(string $name): self
    {
        $this->inviteeName = $name;

        return $this;
    }

    public function getInviteeEmail(): string
    {
        return $this->inviteeEmail;
    }

    public function setInviteeEmail(string $email): self
    {
        $this->inviteeEmail = $email;

        return $this;
    }

    public function getInviteeTimezone(): ?string
    {
        return $this->inviteeTimezone;
    }

    public function setInviteeTimezone(?string $tz): self
    {
        $this->inviteeTimezone = $tz;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function getCancelToken(): string
    {
        return $this->cancelToken;
    }

    public function setCancelToken(string $token): self
    {
        $this->cancelToken = $token;

        return $this;
    }
}
