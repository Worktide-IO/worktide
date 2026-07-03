<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Customer;
use App\Service\Ai\UpgradeOutreachAssistant;
use App\Service\Llm\LlmProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for upgrade-outreach drafting with a stubbed LLM (no real API
 * call). With no CustomerProduct rows the customer is "up to date", so the
 * assistant produces a check-in draft and an empty outdated list; subject/body
 * are cleaned from the model output.
 */
final class UpgradeOutreachAssistantTest extends TestCase
{
    public function testDraftReturnsCleanedSubjectBodyAndEmptyOutdatedList(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(true);
        $llm->method('completeJson')->willReturn([
            'subject' => '  Zeit für ein Update?  ',
            'body' => 'Hallo Acme GmbH, …',
            'reasoning' => 'check-in',
        ]);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $assistant = new UpgradeOutreachAssistant($llm, $em);
        $result = $assistant->draftOutreach((new Customer())->setName('Acme GmbH'));

        self::assertSame('Zeit für ein Update?', $result['suggestion']['subject']);
        self::assertSame('Hallo Acme GmbH, …', $result['suggestion']['body']);
        self::assertSame([], $result['suggestion']['outdatedProducts']);
        self::assertSame('check-in', $result['reasoning']);
    }

    public function testAvailabilityReflectsProvider(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(false);

        $assistant = new UpgradeOutreachAssistant($llm, $this->createStub(EntityManagerInterface::class));

        self::assertFalse($assistant->isAvailable());
    }
}
