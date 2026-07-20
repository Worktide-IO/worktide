<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\Channel;
use App\Entity\Conversation;
use App\Entity\Enum\InboundMuteMatchType;
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

    public function testRuleMatches(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));

        $sender = (new InboundMuteRule())->setMatchType(InboundMuteMatchType::SenderEmail)->setValue('noreply@hetzner.com');
        self::assertTrue($m->ruleMatches($sender, 'noreply@hetzner.com', 'irrelevant'));
        self::assertFalse($m->ruleMatches($sender, 'someone@else.com', 'irrelevant'));
        self::assertFalse($m->ruleMatches($sender, null, 'irrelevant'));

        $subject = (new InboundMuteRule())->setMatchType(InboundMuteMatchType::SubjectContains)->setValue('Verification Code');
        self::assertTrue($m->ruleMatches($subject, null, 'Ihr Hetzner verification code ist 123'));
        self::assertFalse($m->ruleMatches($subject, null, 'Rechnung 42'));
    }

    public function testMatchAndFlagMutesConversation(): void
    {
        $rule = (new InboundMuteRule())->setMatchType(InboundMuteMatchType::SenderEmail)->setValue('noreply@hetzner.com');
        $m = new InboundMuteMatcher($this->repo([$rule]));

        $event = $this->event('noreply@hetzner.com', 'Ihr Hetzner Verification Code ist 798471');
        $now = new \DateTimeImmutable('2026-07-20 12:00:00');

        self::assertTrue($m->matchAndFlag($event, $now));
        self::assertEquals($now, $event->getConversation()?->getMutedAt());
        self::assertSame(1, $rule->getMatchCount());
        self::assertEquals($now, $rule->getLastMatchedAt());
    }

    public function testMatchAndFlagNoRules(): void
    {
        $m = new InboundMuteMatcher($this->repo([]));
        $event = $this->event('kunde@example.com', 'Frage zum Produkt');

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
            ->setChannel($channel)
            ->setExternalId('e1')
            ->setSubject($subject)
            ->setSenderRaw($sender)
            ->setSourceMetadata(['headers' => ['From' => $sender]])
            ->setConversation($conversation);
        $event->setWorkspace($ws);

        return $event;
    }
}
