<?php

declare(strict_types=1);

namespace App\Channels;

use App\Entity\Comment;
use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Document;
use App\Entity\File;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;

/**
 * Maps the `entityType` slug stored on {@see \App\Entity\EntitySync}
 * and {@see \App\Entity\EntityChangeOutbox} to the matching Doctrine
 * class — and back.
 *
 * Slugs are kept short + stable so a future rename of an entity
 * class doesn't break existing sync mappings; the slug is the
 * persistence-level identifier.
 *
 * New entity types are added here by appending to the maps. The
 * resolver throws {@see UnknownEntityTypeException} on misses so
 * adapter authors get a loud signal when they mistype.
 */
final class EntityTypeResolver
{
    /** @var array<string, class-string> */
    private const SLUG_TO_CLASS = [
        'task' => Task::class,
        'project' => Project::class,
        'comment' => Comment::class,
        'document' => Document::class,
        'file' => File::class,
        'time_entry' => TimeEntry::class,
        'customer' => Customer::class,
        'contact' => Contact::class,
    ];

    public function classFor(string $slug): string
    {
        return self::SLUG_TO_CLASS[$slug]
            ?? throw new UnknownEntityTypeException("No Doctrine class registered for entity type '$slug'.");
    }

    public function slugFor(string $class): string
    {
        $flipped = array_flip(self::SLUG_TO_CLASS);
        return $flipped[$class]
            ?? throw new UnknownEntityTypeException("No slug registered for class '$class'.");
    }

    public function isKnownSlug(string $slug): bool
    {
        return isset(self::SLUG_TO_CLASS[$slug]);
    }

    public function tryFromInstance(object $entity): ?string
    {
        $flipped = array_flip(self::SLUG_TO_CLASS);
        $class = $entity::class;
        return $flipped[$class] ?? null;
    }
}
