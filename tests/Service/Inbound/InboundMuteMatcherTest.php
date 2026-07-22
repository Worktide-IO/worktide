<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\InboundRuleCombinator;
use App\Entity\InboundEvent;
use App\Entity\InboundMuteRule;
use App\Entity\Workspace;
use App\Repository\InboundMuteRuleRepository;
use App\Service\Inbound\InboundMuteMatcher;
use PHPUnit\Framework\TestCase;

final class InboundMuteMatcherTest extends TestCase
{
    public function testEmailFromRaw(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));
        self::assertSame('a@b.com', $m->emailFromRaw('Name <A@B.com>'));
        self::assertSame('noreply@hetzner.com', $m->emailFromRaw('noreply@hetzner.com'));
        self::assertNull($m->emailFromRaw('not an email'));
        self::assertNull($m->emailFromRaw(null));
    }

    /** The core "not all noreply" case: sender AND subject, both must hold. */
    public function testAndCombinatorNeedsAllConditions(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));
        $rule = (new InboundMuteRule())
            ->setCombinator(InboundRuleCombinator::And)
            ->setConditions([
                ['field' => 'sender_email', 'operator' => 'contains', 'value' => 'hetzner'],
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'Verification Code'],
            ]);

        self::assertTrue($m->ruleMatches($rule, [
            'sender_email' => 'noreply@hetzner.com', 'subject' => 'Ihr Hetzner Verification Code ist 1',
        ]));
        // Same sender, different subject → NOT muted (the whole point).
        self::assertFalse($m->ruleMatches($rule, [
            'sender_email' => 'noreply@hetzner.com', 'subject' => 'Ihre Rechnung 2026',
        ]));
    }

    public function testOrCombinatorNeedsAnyCondition(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));
        $rule = (new InboundMuteRule())
            ->setCombinator(InboundRuleCombinator::Or)
            ->setConditions([
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'verification code'],
                ['field' => 'subject', 'operator' => 'contains', 'value' => 'security code'],
            ]);
        self::assertTrue($m->ruleMatches($rule, ['subject' => 'Your SECURITY CODE is 5']));
        self::assertFalse($m->ruleMatches($rule, ['subject' => 'Invoice']));
    }

    public function testOperators(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));
        $one = fn (string $op, string $value, ?string $subject) => $m->ruleMatches(
            (new InboundMuteRule())->setConditions([['field' => 'subject', 'operator' => $op, 'value' => $value]]),
            ['subject' => $subject],
        );

        self::assertTrue($one('not_contains', 'invoice', 'Verification code'));
        self::assertFalse($one('not_contains', 'code', 'Verification code'));
        self::assertTrue($one('equals', 'Hello', 'hello'));
        self::assertTrue($one('starts_with', 'ver', 'Verification'));
        self::assertTrue($one('ends_with', 'code', 'Reset code'));
        self::assertTrue($one('regex', 'code\\s+\\d{3}', 'code 798'));
        self::assertFalse($one('regex', 'code\\s+\\d{3}', 'code ABC'));
        // Unavailable field → conservative no-match.
        self::assertFalse($one('not_contains', 'x', null));
    }

    public function testEmptyConditionsNeverMatch(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));
        self::assertFalse($m->ruleMatches((new InboundMuteRule())->setConditions([]), ['subject' => 'anything']));
    }

    public function testNormalizeConditions(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));
        $out = $m->normalizeConditions([['field' => 'sender_email', 'operator' => 'equals', 'value' => ' A@B.com ']]);
        self::assertSame([['field' => 'sender_email', 'operator' => 'equals', 'value' => 'A@B.com']], $out);

        foreach ([[], [['field' => 'x', 'operator' => 'equals', 'value' => 'v']], [['field' => 'subject', 'operator' => 'nope', 'value' => 'v']], [['field' => 'subject', 'operator' => 'equals', 'value' => '']]] as $bad) {
            try {
                $m->normalizeConditions($bad);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMatchAndFlagMutesConversation(): void
    {
        $rule = (new InboundMuteRule())->setConditions([['field' => 'sender_email', 'operator' => 'equals', 'value' => 'noreply@hetzner.com']]);
        $m = new InboundMuteMatcher($this->repo([$rule]));
        $event = $this->event('noreply@hetzner.com', 'Ihr Hetzner Verification Code ist 798471');
        $now = new \DateTimeImmutable('2026-07-20 12:00:00');

        self::assertTrue($m->matchAndFlag($event, $now));
        self::assertEquals($now, $event->getConversation()?->getMutedAt());
        self::assertSame(1, $rule->getMatchCount());
    }

    public function testMatchAndFlagNoRules(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));
        $event = $this->event('kunde@example.com', 'Frage');
        self::assertFalse($m->matchAndFlag($event, new \DateTimeImmutable()));
        self::assertNull($event->getConversation()?->getMutedAt());
    }

    /** @param list<InboundMuteRule> $rules */
    private function repo(array $rules): InboundMuteRuleRepository
    {
        $repo = $this->createStub(InboundMuteRuleRepository::class);
        $repo->method('findEnabledForWorkspace')->willReturn($rules);

        return $repo;
    }

    private function event(string $sender, string $subject): InboundEvent
    {
        $ws = new Workspace();
        $channel = (new Channel())->setName('Mail')->setAdapterCode('email_imap');
        $channel->setWorkspace($ws);
        $conversation = (new Conversation())->setChannel($channel)->setThreadKey('t')->setSubject($subject);
        $conversation->setWorkspace($ws);

        $event = (new InboundEvent())
            ->setChannel($channel)->setExternalId('e1')->setSubject($subject)->setSenderRaw($sender)
            ->setSourceMetadata(['headers' => ['From' => $sender]])
            ->setConversation($conversation);
        $event->setWorkspace($ws);

        return $event;
    }
}
