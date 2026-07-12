<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\Collection;

/**
 * Marks an entity that carries a workspace-wide set of {@see Tag}s.
 *
 * The relation and its accessors are provided by
 * {@see \App\Entity\Trait\TaggableTrait}; implementing this interface is purely
 * a type marker so generic code (unified tag filters, the AI layer, bulk
 * tagging) can detect and operate on taggable records via `instanceof` instead
 * of hard-coding the concrete entity list.
 */
interface TaggableInterface
{
    /** @return Collection<int, Tag> */
    public function getTags(): Collection;

    public function addTag(Tag $tag): static;

    public function removeTag(Tag $tag): static;
}
