<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Repository\UserPreferencesRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user UI preferences. Not workspace-scoped: a user has ONE row that
 * applies across every workspace they belong to (the dashboard layout
 * itself references widget *keys*, not workspace-specific data; the data
 * the widgets render is naturally workspace-scoped via the API).
 *
 * Storage shape for `dashboardLayout` is intentionally a JSON blob so the
 * SPA can evolve its widget schema without a migration every time a new
 * tile lands. Expected shape (validated client-side, not in TCA):
 *
 *   {
 *     "version": 1,
 *     "widgets": [
 *       { "key": "active-timer", "x": 0, "y": 0, "w": 3, "h": 4 },
 *       { "key": "my-projects",  "x": 3, "y": 0, "w": 5, "h": 8 }
 *     ]
 *   }
 *
 * Unknown widget keys are silently ignored on render — that keeps an old
 * layout viable after we remove a widget instead of crashing.
 *
 * Not registered as an ApiResource: callers always go through the
 * /v1/me/preferences endpoint (UserPreferencesController). Forcing that
 * route eliminates the temptation to write to *another* user's prefs.
 */
#[ORM\Entity(repositoryClass: UserPreferencesRepository::class)]
#[ORM\Table(name: 'user_preferences')]
#[ORM\UniqueConstraint(name: 'user_preferences_user_unique', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class UserPreferences
{
    use EntityIdTrait;
    use TimestampableTrait;
    use VersionedTrait;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dashboardLayout = null;

    /**
     * Auto-logout after N minutes of UI inactivity. Null = disabled
     * (default). Enforced client-side by the idle hook; the server has
     * no equivalent kill switch beyond letting the JWT expire.
     */
    #[ORM\Column(nullable: true)]
    private ?int $idleTimeoutMinutes = null;

    /**
     * UUIDs of starred projects. Order is preservation order ("most
     * recently starred first" is up to the SPA — we just store the
     * list). Soft-deleted projects stay in the list and get filtered
     * client-side; the array gets a periodic cleanup on next save.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $favoriteProjectIds = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDashboardLayout(): ?array
    {
        return $this->dashboardLayout;
    }

    /**
     * @param array<string, mixed>|null $layout
     */
    public function setDashboardLayout(?array $layout): self
    {
        $this->dashboardLayout = $layout;

        return $this;
    }

    public function getIdleTimeoutMinutes(): ?int
    {
        return $this->idleTimeoutMinutes;
    }

    public function setIdleTimeoutMinutes(?int $minutes): self
    {
        $this->idleTimeoutMinutes = $minutes;

        return $this;
    }

    /** @return list<string>|null */
    public function getFavoriteProjectIds(): ?array
    {
        return $this->favoriteProjectIds;
    }

    /** @param list<string>|null $ids */
    public function setFavoriteProjectIds(?array $ids): self
    {
        $this->favoriteProjectIds = $ids;

        return $this;
    }
}
