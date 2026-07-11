<?php

declare(strict_types=1);

namespace App\Notification\Chat;

use App\Entity\Enum\ChatProvider;

/**
 * Renders a notification into the JSON body each chat provider's incoming
 * webhook expects. Slack + Mattermost take a simple `{text}` (mrkdwn / markdown,
 * differing only in link + bold syntax); Teams takes a legacy MessageCard with an
 * action button.
 */
final class ChatPayloadBuilder
{
    private const BRAND_COLOR = '0F8C72';

    /**
     * @return array<string, mixed>
     */
    public function build(ChatProvider $provider, string $title, ?string $body, string $actionUrl): array
    {
        $body = trim((string) $body);

        return match ($provider) {
            // Slack mrkdwn: *bold*, links as <url|label>.
            ChatProvider::Slack => [
                'text' => "*{$title}*"
                    . ($body !== '' ? "\n{$body}" : '')
                    . "\n<{$actionUrl}|Öffnen>",
            ],
            // Mattermost markdown: **bold**, standard [label](url).
            ChatProvider::Mattermost => [
                'text' => "**{$title}**"
                    . ($body !== '' ? "\n{$body}" : '')
                    . "\n[Öffnen]({$actionUrl})",
            ],
            ChatProvider::Teams => [
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'summary' => $title,
                'themeColor' => self::BRAND_COLOR,
                'title' => $title,
                'text' => $body !== '' ? $body : $title,
                'potentialAction' => [[
                    '@type' => 'OpenUri',
                    'name' => 'Öffnen',
                    'targets' => [['os' => 'default', 'uri' => $actionUrl]],
                ]],
            ],
        };
    }
}
