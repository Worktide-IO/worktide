<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Agent\AgentCapability;
use App\Service\Ai\AgentActionPlanner;
use App\Service\Llm\LlmProviderInterface;
use PHPUnit\Framework\TestCase;

final class AgentActionPlannerTest extends TestCase
{
    /** @return AgentCapability[] */
    private function catalog(): array
    {
        return [
            new AgentCapability('social_post', 'social_forum_discourse', 'ch-forum', 'Forum', 'social_publish', 500),
            new AgentCapability('outbound_message', 'email_imap', 'ch-mail', 'E-Mail', 'email_outbound'),
        ];
    }

    public function testEmptyContentShortCircuits(): void
    {
        $llm = $this->createMock(LlmProviderInterface::class);
        $llm->expects(self::never())->method('completeJson');

        self::assertSame([], (new AgentActionPlanner($llm))->planDistribution('   ', $this->catalog()));
    }

    public function testValidatesCapsAndDropsBadActions(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('completeJson')->willReturn(['actions' => [
            ['channelId' => 'ch-forum', 'payload' => ['body' => str_repeat('x', 600)], 'rationale' => 'passt'],
            ['channelId' => 'ch-nope', 'payload' => ['body' => 'halluziniert']],                          // not in catalog → dropped
            ['channelId' => 'ch-mail', 'payload' => ['body' => 'Hallo', 'recipient' => 'a@b.test', 'subject' => 'Hi']],
            ['channelId' => 'ch-mail', 'payload' => ['body' => 'ohne Empfänger']],                        // outbound w/o recipient → dropped
        ]]);

        $out = (new AgentActionPlanner($llm))->planDistribution('Neue Version 2.0 ist da.', $this->catalog());

        self::assertCount(2, $out);
        // forum action: archetype/connectorCode come from the catalog, body capped to maxLength
        self::assertSame('social_post', $out[0]['archetype']);
        self::assertSame('social_forum_discourse', $out[0]['connectorCode']);
        self::assertSame(500, mb_strlen($out[0]['payload']['body']));
        // outbound action: recipient + subject carried
        self::assertSame('outbound_message', $out[1]['archetype']);
        self::assertSame('a@b.test', $out[1]['payload']['recipient']);
        self::assertSame('Hi', $out[1]['payload']['subject']);
    }
}
