<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\TimeTrackingSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Workspace-scoped time-tracking configuration. One row per workspace
 * (UNIQUE workspace_id) — clients PATCH the singleton to tune behaviour:
 *
 *   - roundingMinutes:  when a TimeEntry is saved, its duration is rounded
 *                       UP to the nearest N minutes (0 = no rounding).
 *                       Common settings: 5, 15, 30.
 *   - minimumMinutes:   floor for any newly-tracked entry (0 = no floor).
 *                       Useful for "always book at least 15 minutes" rules.
 *   - lockAfterDays:    once a TimeEntry is older than N days, it's marked
 *                       isLocked and the author can no longer change it
 *                       (closed accounting periods). null disables the
 *                       auto-lock.
 *   - allowFutureEntries: defaults to false — refuses startsAt > now.
 *
 * The rounding policy is applied by {@see TimeTrackingPolicy::apply()} at
 * persist time; the lock policy by a scheduled command that walks aged rows.
 */
#[ORM\Entity(repositoryClass: TimeTrackingSettingsRepository::class)]
#[ORM\Table(name: 'time_tracking_settings')]
#[ORM\UniqueConstraint(name: 'time_tracking_settings_workspace_unique', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'TimeTrackingSettings',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('MANAGE', object.getWorkspace())"),
    ],
)]
class TimeTrackingSettings
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 120)]
    private int $roundingMinutes = 0;

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 480)]
    private int $minimumMinutes = 0;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 3650)]
    private ?int $lockAfterDays = null;

    #[ORM\Column]
    private bool $allowFutureEntries = false;

    public function getRoundingMinutes(): int { return $this->roundingMinutes; }
    public function setRoundingMinutes(int $n): self { $this->roundingMinutes = max(0, $n); return $this; }

    public function getMinimumMinutes(): int { return $this->minimumMinutes; }
    public function setMinimumMinutes(int $n): self { $this->minimumMinutes = max(0, $n); return $this; }

    public function getLockAfterDays(): ?int { return $this->lockAfterDays; }
    public function setLockAfterDays(?int $days): self { $this->lockAfterDays = $days; return $this; }

    public function isAllowFutureEntries(): bool { return $this->allowFutureEntries; }
    public function setAllowFutureEntries(bool $v): self { $this->allowFutureEntries = $v; return $this; }
}
