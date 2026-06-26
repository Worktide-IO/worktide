<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\SavedReply;
use App\Entity\User;
use App\Service\SavedReplyRenderer;
use PHPUnit\Framework\TestCase;

final class SavedReplyRendererTest extends TestCase
{
    private SavedReplyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new SavedReplyRenderer();
    }

    public function testInterpolatesKnownVariablesAndLeavesUnknownVerbatim(): void
    {
        $reply = (new SavedReply())->setBody(
            'Hi {{customer.name}} ({{customer.email}}), re: {{conversation.subject}}. — {{agent.name}} {{unknown.var}}',
        );

        $customer = $this->createStub(Customer::class);
        $customer->method('getName')->willReturn('Ada');
        $customer->method('getEmail')->willReturn('ada@x.test');

        $conversation = $this->createStub(Conversation::class);
        $conversation->method('getCustomer')->willReturn($customer);
        $conversation->method('getSubject')->willReturn('Login issue');

        $agent = $this->createStub(User::class);
        $agent->method('getFullName')->willReturn('Sven');
        $agent->method('getEmail')->willReturn('sven@team.test');

        $out = $this->renderer->render($reply, $conversation, $agent);

        self::assertSame('Hi Ada (ada@x.test), re: Login issue. — Sven {{unknown.var}}', $out);
    }

    public function testMissingContextRendersBlanks(): void
    {
        $reply = (new SavedReply())->setBody('Hi {{customer.name}}, I am {{agent.name}}.');

        // No conversation, no agent → context vars resolve to empty strings.
        $out = $this->renderer->render($reply, null, null);

        self::assertSame('Hi , I am .', $out);
    }
}
