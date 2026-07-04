<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Enum\LeadSource;
use App\Entity\Enum\ResearchObjective;
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

    public function testClarifyTreatsNoQuestionsAsReady(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('completeJson')->willReturn(['ready' => false, 'questions' => []]);

        $out = (new ResearchAssistant($llm))->clarify($this->mission(), []);
        self::assertTrue($out['ready']);           // no questions ⇒ ready
        self::assertSame([], $out['questions']);
    }

    public function testClarifyNormalizesQuestionsAndBrief(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('completeJson')->willReturn([
            'ready' => false,
            'questions' => [['question' => 'Welche Region?', 'options' => ['DACH', 'EU', '', 'US']]],
            'brief' => ['query' => 'TYPO3 agencies', 'targetCount' => '1000', 'foo' => 'dropped'],
        ]);

        $out = (new ResearchAssistant($llm))->clarify($this->mission(), []);
        self::assertFalse($out['ready']);
        self::assertCount(1, $out['questions']);
        self::assertSame('q1', $out['questions'][0]['key']);              // key defaulted
        self::assertSame(['DACH', 'EU', 'US'], $out['questions'][0]['options']); // empty dropped
        self::assertSame(1000, $out['brief']['targetCount']);            // coerced to int
        self::assertArrayNotHasKey('foo', $out['brief']);               // unknown key dropped
    }

    public function testClarifyReadyReturnsObjectiveAndClearsQuestions(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('completeJson')->willReturn([
            'ready' => true,
            'objective' => 'partner_search',
            'questions' => [['question' => 'ignored once ready']],
            'brief' => ['query' => 'partner candidates'],
        ]);

        $out = (new ResearchAssistant($llm))->clarify($this->mission(), []);
        self::assertTrue($out['ready']);
        self::assertSame(ResearchObjective::PartnerSearch, $out['objective']);
        self::assertSame([], $out['questions']);
    }

    public function testSuggestMissionsValidatesAndCaps(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('completeJson')->willReturn(['suggestions' => [
            ['prompt' => 'A', 'objective' => 'lead_generation', 'targetCount' => '50', 'brief' => ['query' => 'x']],
            ['objective' => 'partner_search'],                       // no prompt → dropped
            ['prompt' => 'B', 'objective' => 'nonsense'],            // bad objective → general
            ['prompt' => 'C'],
            ['prompt' => 'D'],                                       // beyond cap of 3
        ]]);

        $out = (new ResearchAssistant($llm))->suggestMissions('# snapshot');
        self::assertCount(3, $out);
        self::assertSame('A', $out[0]['prompt']);
        self::assertSame(50, $out[0]['targetCount']);
        self::assertSame('general', $out[1]['objective']);          // 'nonsense' → general
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
