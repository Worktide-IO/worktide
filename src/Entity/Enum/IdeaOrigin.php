<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where an {@see \App\Entity\Idea} came from — drives the portal source label
 * ("von Ihnen" / "vorgeschlagen von der Agentur" / "🤖 KI-Vorschlag").
 */
enum IdeaOrigin: string
{
    case Customer = 'customer';
    case Agency = 'agency';
    case Ai = 'ai';
}
