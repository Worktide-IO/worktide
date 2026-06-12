<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\WorkspaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkspaceRepository::class)]
#[ORM\Table(name: 'workspaces')]
#[ORM\HasLifecycleCallbacks]
class Workspace
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

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
