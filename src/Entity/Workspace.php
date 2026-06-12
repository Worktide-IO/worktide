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
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Repository\WorkspaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkspaceRepository::class)]
#[ORM\Table(name: 'workspaces')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Workspace',
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'slug' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class Workspace
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 60, unique: true)]
    private string $slug;

    #[ORM\Column(length: 8)]
    private string $locale = 'de';

    #[ORM\Column(length: 64)]
    private string $timezone = 'Europe/Berlin';

    /** @var Collection<int, WorkspaceMember> */
    #[ORM\OneToMany(targetEntity: WorkspaceMember::class, mappedBy: 'workspace', orphanRemoval: true)]
    private Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
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

    /** @return Collection<int, WorkspaceMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }
}
