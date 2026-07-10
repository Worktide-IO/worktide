<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Storage + accessors for {@see \App\Entity\TranslatableInterface}.
 *
 * One JSON column carries every translatable field of the entity, so adding
 * the trait costs a single nullable column — no per-field migration, no join.
 * Shape:
 *
 *     { "<field>": { "<locale>": "<value>" }, ... }
 *
 * The map only holds alternate-locale strings; the base column remains the
 * canonical source-language value. `translations` is a plain property, so the
 * default (de)serializer reads it for editing UIs and accepts writes on
 * PATCH — no custom denormalizer required.
 */
trait TranslatableTrait
{
    /**
     * @var array<string, array<string, string>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $translations = null;

    /**
     * @return array<string, array<string, string>>|null
     */
    public function getTranslations(): ?array
    {
        return $this->translations;
    }

    /**
     * @param array<string, array<string, string>>|null $translations
     */
    public function setTranslations(?array $translations): self
    {
        // Normalise an empty map back to null so the column doesn't churn
        // between `{}` and NULL on round-trips.
        $this->translations = ($translations === null || $translations === []) ? null : $translations;

        return $this;
    }

    public function getTranslation(string $field, string $locale): ?string
    {
        $value = $this->translations[$field][$locale] ?? null;

        return (is_string($value) && $value !== '') ? $value : null;
    }
}
