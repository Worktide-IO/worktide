<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\Enum\ChatProvider;
use App\Notification\Chat\ChatPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class ChatPayloadBuilderTest extends TestCase
{
    private ChatPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ChatPayloadBuilder();
    }

    public function testSlackUsesMrkdwnAndAngleLink(): void
    {
        $p = $this->builder->build(ChatProvider::Slack, 'Neue Aufgabe', 'WORK-1 zugewiesen', 'https://app.example/tasks');
        self::assertSame(
            "*Neue Aufgabe*\nWORK-1 zugewiesen\n<https://app.example/tasks|Öffnen>",
            $p['text'],
        );
    }

    public function testMattermostUsesMarkdownLink(): void
    {
        $p = $this->builder->build(ChatProvider::Mattermost, 'Neue Aufgabe', null, 'https://app.example/tasks');
        self::assertSame("**Neue Aufgabe**\n[Öffnen](https://app.example/tasks)", $p['text']);
    }

    public function testTeamsBuildsMessageCardWithAction(): void
    {
        $p = $this->builder->build(ChatProvider::Teams, 'Neue Aufgabe', 'Details', 'https://app.example/tasks');
        self::assertSame('MessageCard', $p['@type']);
        self::assertSame('Neue Aufgabe', $p['title']);
        self::assertSame('Details', $p['text']);
        self::assertSame('https://app.example/tasks', $p['potentialAction'][0]['targets'][0]['uri']);
    }
}
