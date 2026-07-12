<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Enum\TagScope;
use App\Entity\Tag;
use App\Entity\Workspace;
use App\Repository\TagRepository;
use App\Service\Ai\TagSuggestionAssistant;
use App\Service\Llm\LlmProviderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for tag suggestion with a stubbed LLM (no real API call):
 * only workspace-real tags in the record's scope (or "Any") may be returned as
 * matches; everything else the model proposes is surfaced as suggestedNewTags.
 */
final class TagSuggestionAssistantTest extends TestCase
{
    public function testPrefersExistingTagsAndCollectsNewOnes(): void
    {
        $assistant = $this->assistant(
            existing: ['vip' => TagScope::Contact, 'b2b' => TagScope::Any],
            llmJson: [
                'tags' => ['VIP', 'enterprise'],  // VIP → canonical "vip"; enterprise is new
                'reasoning' => '  CTO at an enterprise account.  ',
            ],
        );

        $out = $assistant->suggest('CTO at ACME Corp', TagScope::Contact, new Workspace());

        self::assertSame(['vip'], array_map(static fn (Tag $t): string => $t->getName(), $out['tags']));
        self::assertSame(['enterprise'], $out['suggestedNewTags']);
        self::assertSame('CTO at an enterprise account.', $out['reasoning']);
    }

    public function testExcludesTagsFromOtherScopes(): void
    {
        // A tag named "urgent" exists but only in Task scope → not selectable for a Contact,
        // so the model's pick lands in suggestedNewTags instead of matching.
        $assistant = $this->assistant(
            existing: ['urgent' => TagScope::Task, 'vip' => TagScope::Contact],
            llmJson: ['tags' => ['urgent', 'vip']],
        );

        $out = $assistant->suggest('some text', TagScope::Contact, new Workspace());

        self::assertSame(['vip'], array_map(static fn (Tag $t): string => $t->getName(), $out['tags']));
        self::assertSame(['urgent'], $out['suggestedNewTags']);
    }

    public function testEmptyContextReturnsNothing(): void
    {
        $assistant = $this->assistant(existing: ['vip' => TagScope::Contact], llmJson: ['tags' => ['vip']]);

        $out = $assistant->suggest('   ', TagScope::Contact, new Workspace());

        self::assertSame([], $out['tags']);
        self::assertSame([], $out['suggestedNewTags']);
        self::assertNull($out['reasoning']);
    }

    public function testAvailabilityReflectsProvider(): void
    {
        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(false);

        $assistant = new TagSuggestionAssistant($llm, $this->createStub(TagRepository::class));

        self::assertFalse($assistant->isAvailable());
    }

    // --- helpers ----------------------------------------------------

    /**
     * @param array<string, TagScope> $existing  workspace tags: name => scope
     * @param array<string, mixed>    $llmJson
     */
    private function assistant(array $existing, array $llmJson): TagSuggestionAssistant
    {
        $ws = new Workspace();

        $llm = $this->createStub(LlmProviderInterface::class);
        $llm->method('isConfigured')->willReturn(true);
        $llm->method('completeJson')->willReturn($llmJson);
        $llm->method('getModel')->willReturn('claude-test');

        $tagRepo = $this->createStub(TagRepository::class);
        $tags = [];
        foreach ($existing as $name => $scope) {
            $tags[] = (new Tag())->setName($name)->setScope($scope)->setWorkspace($ws);
        }
        $tagRepo->method('findBy')->willReturn($tags);

        return new TagSuggestionAssistant($llm, $tagRepo);
    }
}
