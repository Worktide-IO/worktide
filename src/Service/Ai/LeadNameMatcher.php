<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * Compares company/person names for dedup. Folds a name to a comparable key
 * (strip highlight markup, transliterate umlauts so "RÖHM" ≡ "Roehm", drop
 * legal-form words like GmbH/AG and any non-alphanumerics), then treats two
 * names as the same entity when the keys are equal or ≥90% similar. The
 * similarity gate keeps a typo-tolerant search hit from causing a false positive
 * ("Probst Maschinenbau" is NOT "Probst Fenster").
 */
final class LeadNameMatcher
{
    private const SIMILARITY_THRESHOLD = 90.0;

    public function normalize(string $name): string
    {
        $n = mb_strtolower(trim(strip_tags($name)));
        $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $n = preg_replace('/\b(gmbh|mbh|ag|se|kgaa|kg|ohg|gbr|ug|ltd|inc|llc|co|corp|company|e\.?\s?v\.?|und|and)\b/', ' ', $n) ?? $n;
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? $n;

        return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
    }

    /** True if both names normalize to the same key or are ≥90% similar. */
    public function matches(string $a, string $b): bool
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb) {
            return true;
        }
        similar_text($na, $nb, $pct);

        return $pct >= self::SIMILARITY_THRESHOLD;
    }
}
