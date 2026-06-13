<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
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
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\AbsenceRegionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Regional public-holiday calendar (e.g. "Bayern", "France"). Users assigned
 * to a region auto-inherit absences from imported public holidays.
 *
 * Schema only — public-holiday ETL is a future task. countryCode is ISO 3166-1
 * alpha-2, location is a free-form sub-region name.
 */
#[ORM\Entity(repositoryClass: AbsenceRegionRepository::class)]
#[ORM\Table(name: 'absence_regions')]
#[ORM\UniqueConstraint(name: 'absence_region_workspace_country_location_unique', columns: ['workspace_id', 'country_code', 'location'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'AbsenceRegion',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'countryCode' => 'exact', 'location' => 'partial', 'name' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'countryCode'])]
class AbsenceRegion
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 2)]
    private string $countryCode;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'absence_region_users')]
    private Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getCountryCode(): string { return $this->countryCode; }
    public function setCountryCode(string $code): self { $this->countryCode = strtoupper($code); return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }

    /** @return Collection<int, User> */
    public function getUsers(): Collection { return $this->users; }
}
