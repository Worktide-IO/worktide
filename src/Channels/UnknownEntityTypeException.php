<?php

declare(strict_types=1);

namespace App\Channels;

/**
 * Thrown when a {@see EntityTypeResolver} call references a slug
 * or class that hasn't been registered — typically an adapter
 * typo'd `supportedEntityTypes()`, or someone deleted a class
 * without updating the slug map.
 */
final class UnknownEntityTypeException extends \LogicException
{
}
