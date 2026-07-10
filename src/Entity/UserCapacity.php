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
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Repository\UserCapacityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user weekly working capacity in MINUTES per weekday. Replaces awork's
 * `UserWeeklyCapacity` object — flattened into the row so reporting queries
 * don't need to join an embedded.
 *
 * One row per user, enforced by the unique constraint on user_id. Default
 * = 0 minutes everywhere; UI should pre-fill 480 (8h) on weekdays for new
 * users.
 */
#[ORM\Entity(repositoryClass: UserCapacityRepository::class)]
#[ORM\Table(name: 'user_capacities')]
#[ORM\UniqueConstraint(name: 'user_capacity_user_unique', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'UserCapacity',
    operations: [
        // Per-user capacity rows. Reads are scoped to the caller's OWN rows by
        // WorkspaceScopeExtension (root.user == caller); every item/write op is
        // self-only. Previously any ROLE_USER could read or modify every user's
        // capacity across all tenants.
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "object.getUser() == user"),
        new Post(securityPostDenormalize: "object.getUser() == user"),
        new Patch(security: "object.getUser() == user"),
        new Delete(security: "object.getUser() == user"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['user' => 'exact'])]
class UserCapacity
{
    use EntityIdTrait;
    use TimestampableTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'integer')]
    private int $monMinutes = 0;

    #[ORM\Column(type: 'integer')]
    private int $tueMinutes = 0;

    #[ORM\Column(type: 'integer')]
    private int $wedMinutes = 0;

    #[ORM\Column(type: 'integer')]
    private int $thuMinutes = 0;

    #[ORM\Column(type: 'integer')]
    private int $friMinutes = 0;

    #[ORM\Column(type: 'integer')]
    private int $satMinutes = 0;

    #[ORM\Column(type: 'integer')]
    private int $sunMinutes = 0;

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getMonMinutes(): int { return $this->monMinutes; }
    public function setMonMinutes(int $m): self { $this->monMinutes = max(0, $m); return $this; }

    public function getTueMinutes(): int { return $this->tueMinutes; }
    public function setTueMinutes(int $m): self { $this->tueMinutes = max(0, $m); return $this; }

    public function getWedMinutes(): int { return $this->wedMinutes; }
    public function setWedMinutes(int $m): self { $this->wedMinutes = max(0, $m); return $this; }

    public function getThuMinutes(): int { return $this->thuMinutes; }
    public function setThuMinutes(int $m): self { $this->thuMinutes = max(0, $m); return $this; }

    public function getFriMinutes(): int { return $this->friMinutes; }
    public function setFriMinutes(int $m): self { $this->friMinutes = max(0, $m); return $this; }

    public function getSatMinutes(): int { return $this->satMinutes; }
    public function setSatMinutes(int $m): self { $this->satMinutes = max(0, $m); return $this; }

    public function getSunMinutes(): int { return $this->sunMinutes; }
    public function setSunMinutes(int $m): self { $this->sunMinutes = max(0, $m); return $this; }

    public function getWeeklyMinutes(): int
    {
        return $this->monMinutes + $this->tueMinutes + $this->wedMinutes
             + $this->thuMinutes + $this->friMinutes + $this->satMinutes + $this->sunMinutes;
    }
}
