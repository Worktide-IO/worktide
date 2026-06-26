<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Pulls mentioned-user IRIs out of a rich-text body. The SPA (BlockNote /
 * comment editor) serialises a mention as a `/v1/users/<uuid>` IRI inside the
 * node props; a substring scan finds them all without parsing the block tree —
 * UUIDs are unique enough that false matches don't happen.
 *
 * Shared by {@see \App\EventSubscriber\DocumentMentionNotifier} and
 * {@see \App\EventSubscriber\ConversationNoteMentionNotifier} so both detect
 * mentions identically.
 */
final class MentionExtractor
{
    /**
     * @return list<string> distinct `/v1/users/<uuid>` IRIs, in first-seen order
     */
    public function iris(string $body): array
    {
        if ($body === '') {
            return [];
        }
        if (!preg_match_all('#/v1/users/[0-9a-f-]{36}#', $body, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }
}
