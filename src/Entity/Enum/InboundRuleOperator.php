<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Comparison an {@see \App\Entity\InboundMuteRule} condition applies to a field.
 * Mirrors Thunderbird's filter operators (incl. negation + regex). Text
 * comparisons are case-insensitive; `regex` uses PCRE (delimiters added, `i`).
 */
enum InboundRuleOperator: string
{
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case Regex = 'regex';
}
