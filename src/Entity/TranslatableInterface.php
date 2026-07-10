<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Marks an entity whose user-visible text fields carry per-locale overrides.
 *
 * The base column (e.g. `name`) always holds the source-language value and is
 * never mutated — UniqueConstraints, SearchFilters and existing rows stay
 * intact. Alternate-locale strings live in a single `translations` JSON column
 * (see {@see \App\Entity\Trait\TranslatableTrait}) shaped as:
 *
 *     { "<field>": { "<locale>": "<value>" }, ... }
 *
 * Output resolution is centralised in {@see \App\Serializer\TranslatableNormalizer}:
 * on serialization it overlays each translatable field with the value for the
 * active locale (falling back to the base column) and still emits the raw
 * `translations` map for editing UIs. No per-entity serializer config needed —
 * implementing this interface is enough to opt in.
 */
interface TranslatableInterface
{
    /**
     * Serialized property names that carry translatable text.
     *
     * Only scalar string fields are supported for now (name/label/title/
     * description/body/…); nested/array structures are out of scope.
     *
     * @return list<string>
     */
    public static function translatableFields(): array;

    /**
     * @return array<string, array<string, string>>|null
     */
    public function getTranslations(): ?array;

    /**
     * Resolved override for a single field+locale, or null when absent/empty.
     */
    public function getTranslation(string $field, string $locale): ?string;
}
