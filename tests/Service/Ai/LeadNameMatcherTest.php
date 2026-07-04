<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\LeadNameMatcher;
use PHPUnit\Framework\TestCase;

final class LeadNameMatcherTest extends TestCase
{
    public function testFoldsUmlautsAndLegalForms(): void
    {
        $m = new LeadNameMatcher();
        self::assertSame('roehm', $m->normalize('RÖHM'));
        self::assertTrue($m->matches('RÖHM', 'Roehm GmbH'));      // ö→oe + drop GmbH
        self::assertTrue($m->matches('Acme AG', 'ACME'));         // drop AG, case-fold
        self::assertTrue($m->matches('Müller & Co. KG', 'Mueller')); // translit + drop &/Co/KG
    }

    public function testDistinctNamesDoNotMatch(): void
    {
        $m = new LeadNameMatcher();
        self::assertFalse($m->matches('Probst Maschinenbau', 'Probst Fenster'));
    }

    public function testEmptyNeverMatches(): void
    {
        $m = new LeadNameMatcher();
        self::assertSame('', $m->normalize('  GmbH '));   // only a legal form → empty key
        self::assertFalse($m->matches('', 'Acme'));
        self::assertFalse($m->matches('GmbH', 'AG'));
    }
}
