<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\SavedReply;
use App\Entity\User;

/**
 * Interpolates a {@see SavedReply}'s `{{variable}}` placeholders against a
 * conversation + agent context. Unknown placeholders are left verbatim (so a
 * typo'd variable is visible, not silently blanked); a missing context value
 * (e.g. no customer on the conversation) renders as an empty string.
 *
 * Supported variables:
 *   {{customer.name}} {{customer.email}}
 *   {{conversation.subject}}
 *   {{agent.name}} {{agent.email}}
 */
final class SavedReplyRenderer
{
    public function render(SavedReply $reply, ?Conversation $conversation = null, ?User $agent = null): string
    {
        $customer = $conversation?->getCustomer();

        $vars = [
            '{{customer.name}}' => $customer?->getName() ?? '',
            '{{customer.email}}' => $customer?->getEmail() ?? '',
            '{{conversation.subject}}' => $conversation?->getSubject() ?? '',
            '{{agent.name}}' => $agent?->getFullName() ?? '',
            '{{agent.email}}' => $agent?->getEmail() ?? '',
        ];

        return strtr($reply->getBody(), $vars);
    }
}
