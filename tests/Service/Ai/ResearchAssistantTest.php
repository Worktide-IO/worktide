<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Enum\LeadSource;
use App\Entity\ResearchMission;
use App\Service\Ai\ResearchAssistant;
use App\Service\ExternalSearch\ExternalSearchResult;
use App\Service\Llm\LlmProviderInterface;
use PHPUnit\Framework\TestCase;

final class ResearchAssistantTest extends TestCase
{
    public function testEmptyResultsShortCircuitsWithoutCallingLlm(): void
    {
        $llm = $this->createMock(LlmProviderInterface::class);
        $llm->expects(self::never())->method('completeJson');

        $out = (new ResearchAssistant($llm))->extractLeads($this->mission(), []);
        self::assertSame([], $out['leads']);
        self::assertNull($out['reasoning']);
    }

    public function testValidatesAndClampsLeads(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('completeJson')->willReturn([
            'leads' => [
                ['name' => '  Acme GmbH ', 'isCompany' => true, 'email' => 'HI@acme.test', 'fitScore' => 150, 'scoreReason' => 'strong', 'sourceUrl' => 'https://acme.test'],
                ['name' => 'Jane Doe', 'isCompany' => false, 'fitScore' => -5],
            ],
            'reasoning' => 'because',
        ]);

        $out = (new ResearchAssistant($llm))->extractLeads($this->mission(), [$this->hit()]);

        self::assertCount(2, $out['leads']);
        self::assertSame('Acme GmbH', $out['leads'][0]['name']);       // trimmed
        self::assertSame(100, $out['leads'][0]['fitScore']);            // clamped to 100
        self::assertSame('HI@acme.test', $out['leads'][0]['email']);
        self::assertFalse($out['leads'][1]['isCompany']);
        self::assertSame(0, $out['leads'][1]['fitScore']);             // clamped to 0
        self::assertSame('because', $out['reasoning']);
    }

    public function testDropsNamelessLeads(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('completeJson')->willReturn([
            'leads' => [
                ['name' => '', 'fitScore' => 90],
                ['email' => 'x@y.test'],
                ['name' => 'Real GmbH', 'fitScore' => 50],
            ],
        ]);

        $out = (new ResearchAssistant($llm))->extractLeads($this->mission(), [$this->hit()]);
        self::assertCount(1, $out['leads']);
        self::assertSame('Real GmbH', $out['leads'][0]['name']);
    }

    private function mission(): ResearchMission
    {
        return (new ResearchMission())->setPrompt('find TYPO3 agencies in DACH');
    }

    private function hit(): ExternalSearchResult
    {
        return new ExternalSearchResult('Acme', 'https://acme.test', 'a snippet', LeadSource::WebSearch, 'tavily');
    }
}
