<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\TranslatableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\MeetingTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A bookable meeting type (Calendly-style) — e.g. "30-min Erstgespräch". Public
 * visitors book slots at `/book/{slug}`; slots are derived from the weekly
 * `availability` windows minus existing bookings (see BookingSlotService).
 *
 * Dual API surface, mirroring {@see PublicForm}: staff manage the definition via
 * this workspace-scoped API Platform resource (`/v1/meeting_types`), while the
 * anonymous, slug-as-credential booking flow lives in a plain public controller
 * ({@see \App\Controller\Api\PublicBookingController}) — NOT through API Platform
 * (so it bypasses the member-only WorkspaceScopeExtension).
 *
 * `securityPostDenormalize` on Post so the workspace grant is checked after the
 * body's `workspace` IRI is bound (the audited self-escalation class).
 */
#[ORM\Entity(repositoryClass: MeetingTypeRepository::class)]
#[ORM\Table(name: 'meeting_types')]
#[ORM\UniqueConstraint(name: 'meeting_type_slug_uniq', columns: ['slug'])]
#[ORM\Index(name: 'meeting_type_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'MeetingType',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(securityPostDenormalize: "is_granted('EDIT', object.getWorkspace())"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'slug' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled'])]
class MeetingType implements TranslatableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use TranslatableTrait;

    /** Globally-unique public booking slug (the `/book/{slug}` credential). */
    #[ORM\Column(length: 60)]
    private string $slug = '';

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $durationMinutes = 30;

    #[ORM\Column]
    private bool $isEnabled = true;

    /** Who hosts the meeting — shown to the invitee and emailed the booking. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $host = null;

    /** video | phone | in_person — free string, validated in the admin UI. */
    #[ORM\Column(length: 20)]
    private string $locationType = 'video';

    /** Location detail (video link note, phone number, address). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $locationDetail = null;

    /** Host timezone the availability windows are expressed in. */
    #[ORM\Column(length: 64)]
    private string $timezone = 'Europe/Berlin';

    #[ORM\Column]
    private int $bufferBeforeMinutes = 0;

    #[ORM\Column]
    private int $bufferAfterMinutes = 0;

    /** Minimum lead time before a slot can be booked. */
    #[ORM\Column]
    private int $minNoticeMinutes = 240;

    /** How far into the future slots are offered. */
    #[ORM\Column]
    private int $maxAdvanceDays = 30;

    /**
     * Weekly availability windows, in the host `timezone`. Each entry:
     *   { "weekday": 1..7 (ISO-8601, 1=Mon), "start": "HH:MM", "end": "HH:MM" }
     *
     * @var list<array{weekday: int, start: string, end: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $availability = [];

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $minutes): self
    {
        $this->durationMinutes = max(1, $minutes);

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    // Named setEnabled (not setIsEnabled) so API Platform's write attribute
    // matches the read attribute (`enabled` from isEnabled()) — otherwise the
    // getter serializes as `enabled` but the setter expects `isEnabled`.
    public function setEnabled(bool $enabled): self
    {
        $this->isEnabled = $enabled;

        return $this;
    }

    public function getHost(): ?User
    {
        return $this->host;
    }

    public function setHost(?User $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getLocationType(): string
    {
        return $this->locationType;
    }

    public function setLocationType(string $type): self
    {
        $this->locationType = $type;

        return $this;
    }

    public function getLocationDetail(): ?string
    {
        return $this->locationDetail;
    }

    public function setLocationDetail(?string $detail): self
    {
        $this->locationDetail = $detail;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function getBufferBeforeMinutes(): int
    {
        return $this->bufferBeforeMinutes;
    }

    public function setBufferBeforeMinutes(int $minutes): self
    {
        $this->bufferBeforeMinutes = max(0, $minutes);

        return $this;
    }

    public function getBufferAfterMinutes(): int
    {
        return $this->bufferAfterMinutes;
    }

    public function setBufferAfterMinutes(int $minutes): self
    {
        $this->bufferAfterMinutes = max(0, $minutes);

        return $this;
    }

    public function getMinNoticeMinutes(): int
    {
        return $this->minNoticeMinutes;
    }

    public function setMinNoticeMinutes(int $minutes): self
    {
        $this->minNoticeMinutes = max(0, $minutes);

        return $this;
    }

    public function getMaxAdvanceDays(): int
    {
        return $this->maxAdvanceDays;
    }

    public function setMaxAdvanceDays(int $days): self
    {
        $this->maxAdvanceDays = max(1, $days);

        return $this;
    }

    /** @return list<array{weekday: int, start: string, end: string}> */
    public function getAvailability(): array
    {
        return $this->availability;
    }

    /** @param list<array{weekday: int, start: string, end: string}> $availability */
    public function setAvailability(array $availability): self
    {
        $this->availability = array_values($availability);

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function translatableFields(): array
    {
        return ['title', 'description'];
    }
}
