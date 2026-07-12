<?php

declare(strict_types=1);

namespace App\Tests\Service\PromptInjection;

use App\Service\Agent\AgentCapability;
use App\Service\Ai\AgentActionPlanner;
use App\Tests\Support\PromptInjectionPayloads;
use App\Tests\Support\RecordingLlmProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prompt-injection resilience for the autonomous distribution planner — the
 * closest thing to an "agent that decides outbound actions from content". The
 * `$content` is untrusted (it may itself be a scraped article or a customer
 * submission), so an injection could try to make the agent exfiltrate via a
 * channel the workspace doesn't own, or blast an email to the attacker.
 *
 * Guardrails proven here: every action must name a channelId from the workspace's
 * own capability catalog (a hijacked model naming an off-catalog "exfil" channel
 * is dropped), and an outbound_message with no recipient is dropped. The content
 * also never contaminates the system prompt.
 */
final class AgentActionPlannerInjectionTest extends TestCase
{
    public function testInjectedContentNeverReachesTheSystemPrompt(): void
    {
        foreach (PromptInjectionPayloads::all() as $name => $payload) {
            $llm = new RecordingLlmProvider(['actions' => []]);
            (new AgentActionPlanner($llm))->planDistribution($payload, [$this->social()]);

            self::assertStringNotContainsString(
                PromptInjectionPayloads::MARKER,
                $llm->lastSystem(),
                "payload '{$name}' leaked into the planner system prompt",
            );
            self::assertStringContainsString(PromptInjectionPayloads::MARKER, $llm->lastUser());
        }
    }

    public function testHijackedModelCannotTargetAChannelOutsideTheCatalog(): void
    {
        // The model "obeyed" an injection and proposed an off-catalog exfil channel
        // (and, for good measure, a valid one) — only the catalogued one survives.
        $llm = new RecordingLlmProvider(['actions' => [
            ['channelId' => 'attacker-exfil-channel', 'payload' => ['body' => 'leak everything'], 'rationale' => 'x'],
            ['channelId' => 'chan-social', 'payload' => ['body' => 'legit post'], 'rationale' => 'ok'],
        ]]);

        $out = (new AgentActionPlanner($llm))->planDistribution(
            PromptInjectionPayloads::EXFIL_EMAIL,
            [$this->social()],
        );

        $channelIds = array_column($out, 'channelId');
        self::assertNotContains('attacker-exfil-channel', $channelIds, 'off-catalog channel must be dropped');
        self::assertSame(['chan-social'], $channelIds, 'only catalogued channels survive');
    }

    public function testHijackedOutboundMessageWithoutRecipientIsDropped(): void
    {
        // Injection tries to make the agent email the attacker, but omits/forges
        // the recipient handling — an outbound_message with no recipient is dropped.
        $llm = new RecordingLlmProvider(['actions' => [
            ['channelId' => 'chan-mail', 'payload' => ['body' => 'secret data'], 'rationale' => 'x'],
        ]]);

        $out = (new AgentActionPlanner($llm))->planDistribution(
            PromptInjectionPayloads::EXFIL_EMAIL,
            [$this->mail()],
        );

        self::assertSame([], $out, 'outbound_message without a recipient must not be produced');
    }

    private function social(): AgentCapability
    {
        return new AgentCapability('social_post', 'mastodon', 'chan-social', 'Mastodon', 'social_publish', 500);
    }

    private function mail(): AgentCapability
    {
        return new AgentCapability('outbound_message', 'email', 'chan-mail', 'Mailbox', 'email_outbound');
    }
}
