<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What an {@see \App\Entity\AIRecommendation} applies to. Polymorphic like
 * {@see CommentTarget} — the (target, targetId) pair identifies the ticket.
 */
enum RecommendationTarget: string
{
    case Task = 'task';
    case Conversation = 'conversation';
    case Product = 'product';
    case Customer = 'customer';
    case Workspace = 'workspace';
}
