<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Tag;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Storage + accessors for {@see \App\Entity\TaggableInterface}.
 *
 * Adding the trait makes an entity carry a many-to-many set of workspace-wide
 * {@see Tag}s — the same reusable Tag entity used everywhere, keyed by its
 * {@see \App\Entity\Enum\TagScope} so palettes stay per-context. There is no
 * explicit `#[ORM\JoinTable]`: Doctrine's underscore naming strategy derives a
 * per-entity join table (`contact_tag`, `lead_tag`, `customer_system_tag`, …),
 * so no two using entities can collide and each gets its own table.
 *
 * `tags` is a plain mapped property, so the default (de)serializer reads it as
 * an IRI array and accepts IRI-array writes on PATCH/POST — no serialization
 * groups and no custom denormalizer required, matching the four entities that
 * were taggable before this trait existed (Project/Task/Customer/ProjectTemplate).
 *
 * Using entities MUST initialize the collection in their constructor:
 *
 *     $this->tags = new ArrayCollection();
 */
trait TaggableTrait
{
    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    private Collection $tags;

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }
}
