<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CalendarBusyBlockRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A busy interval imported from a staff member's external calendar — the cache
 * the booking slot engine subtracts so clients can't book over the host's real
 * appointments. Not an API resource: written only by the calendar sync
 * (delete-all-then-reinsert per user), read only by BookingSlotService.
 *
 * `startAt`/`endAt` follow the app's naive-default-tz datetime convention (see
 * setters), like {@see Booking}.
 */
#[ORM\Entity(repositoryClass: CalendarBusyBlockRepository::class)]
#[ORM\Table(name: 'calendar_busy_blocks')]
#[ORM\Index(name: 'busy_owner_start_idx', columns: ['owner_id', 'start_at'])]
#[ORM\Index(name: 'busy_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
class CalendarBusyBlock
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $endAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalUid = null;

    #[ORM\Column(length: 16)]
    private string $source = 'ics';

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): self
    {
        // Naive-default-tz convention (see Booking::setStartAt).
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

    public function getExternalUid(): ?string
    {
        return $this->externalUid;
    }

    public function setExternalUid(?string $uid): self
    {
        $this->externalUid = $uid;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }
}
