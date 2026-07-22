<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How an {@see \App\Entity\InboundMuteRule}'s conditions combine — Thunderbird's
 * "Alle Bedingungen erfüllen" (AND) vs "Mindestens eine Bedingung erfüllen" (OR).
 */
enum InboundRuleCombinator: string
{
    case And = 'and';
    case Or = 'or';
}
