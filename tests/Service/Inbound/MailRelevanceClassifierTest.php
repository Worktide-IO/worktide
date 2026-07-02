<?php

declare(strict_types=1);

namespace App\Tests\Service\Inbound;

use App\Entity\InboundEvent;
use App\Service\Inbound\MailRelevanceClassifier;
use PHPUnit\Framework\TestCase;

/**
 * The LLM-free gate: only genuine, human, non-bulk mail is "actionable" and thus
 * eligible for an AI ticket suggestion. Newsletters/automated mail stay in the
 * inbox but never trigger an LLM call.
 */
final class MailRelevanceClassifierTest extends TestCase
{
    private MailRelevanceClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new MailRelevanceClassifier();
    }

    public function testPlainHumanMailIsActionable(): void
    {
        self::assertTrue($this->classifier->isActionable($this->event(['From' => 'Kunde <kunde@example.com>'])));
    }

    public function testListUnsubscribeIsNotActionable(): void
    {
        self::assertFalse($this->classifier->isActionable($this->event([
            'From' => 'news@example.com',
            'List-Unsubscribe' => '<mailto:unsub@example.com>',
        ])));
    }

    public function testBulkPrecedenceIsNotActionable(): void
    {
        self::assertFalse($this->classifier->isActionable($this->event(['From' => 'x@example.com', 'Precedence' => 'bulk'])));
    }

    public function testAutoSubmittedIsNotActionable(): void
    {
        self::assertFalse($this->classifier->isActionable($this->event(['From' => 'x@example.com', 'Auto-Submitted' => 'auto-generated'])));
    }

    public function testAutoSubmittedNoIsActionable(): void
    {
        self::assertTrue($this->classifier->isActionable($this->event(['From' => 'x@example.com', 'Auto-Submitted' => 'no'])));
    }

    public function testNoReplySenderIsNotActionable(): void
    {
        self::assertFalse($this->classifier->isActionable($this->event(['From' => 'No Reply <no-reply@example.com>'])));
    }

    public function testMailerDaemonIsNotActionable(): void
    {
        self::assertFalse($this->classifier->isActionable($this->event(['From' => 'mailer-daemon@example.com'])));
    }

    /**
     * @param array<string, string> $headers
     */
    private function event(array $headers): InboundEvent
    {
        return (new InboundEvent())->setSourceMetadata(['headers' => $headers]);
    }
}
