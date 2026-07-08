<?php

declare(strict_types=1);

namespace App\Tests\Channels;

use App\Channels\EntityApplier;
use PHPUnit\Framework\TestCase;

/**
 * Unit-covers {@see EntityApplier::coerceForSetter()}, the pre-setter type
 * coercion that turns ISO strings from inbound snapshots into
 * DateTimeImmutable before they reach typed entity setters.
 *
 * Regression guard: Redmine's start_date/due_date arrive as date-only
 * strings ("YYYY-MM-DD"). They must coerce to DateTimeImmutable — otherwise
 * Task::setStartOn(?DateTimeImmutable) raises a TypeError and aborts the
 * whole channel pull.
 *
 * coerceForSetter is private and stateless, so we invoke it on an
 * uninitialised instance via reflection rather than wiring the six
 * services the constructor needs.
 */
final class EntityApplierCoercionTest extends TestCase
{
    private function coerce(mixed $value): mixed
    {
        $ref = new \ReflectionClass(EntityApplier::class);
        $applier = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('coerceForSetter');
        $method->setAccessible(true);

        return $method->invoke($applier, 'setStartOn', new \stdClass(), $value);
    }

    public function testDateOnlyStringCoercesToDateTime(): void
    {
        $result = $this->coerce('2026-07-08');

        self::assertInstanceOf(\DateTimeImmutable::class, $result);
        self::assertSame('2026-07-08 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testFullDateTimeStringStillCoerces(): void
    {
        $result = $this->coerce('2026-07-08T14:30:00+02:00');

        self::assertInstanceOf(\DateTimeImmutable::class, $result);
        self::assertSame('2026-07-08 14:30:00', $result->format('Y-m-d H:i:s'));
    }

    public function testNonDateStringPassesThroughUntouched(): void
    {
        self::assertSame('Formular Projektanfrage', $this->coerce('Formular Projektanfrage'));
    }

    public function testNullPassesThrough(): void
    {
        self::assertNull($this->coerce(null));
    }
}
