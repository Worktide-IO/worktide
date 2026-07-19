<?php

declare(strict_types=1);

namespace App\Tests\Service\PromptInjection;

use App\Entity\Enum\TagScope;
use App\Entity\Tag;
use App\Entity\Workspace;
use App\Repository\TagRepository;
use App\Service\Ai\TagSuggestionAssistant;
use App\Service\Llm\AiUsageContext;
use App\Tests\Support\PromptInjectionPayloads;
use App\Tests\Support\RecordingLlmProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prompt-injection resilience for tag suggestions. The `$context` is arbitrary
 * record text (ticket, comment, document …), i.e. attacker-controllable.
 *
 * Key guardrail: only tags that REALLY exist in the workspace can be returned as
 * applied `tags`; anything the (possibly hijacked) model invents is quarantined
 * into `suggestedNewTags`, which a human must consciously create. So injection
 * can never force an arbitrary label onto a record.
 */
final class TagSuggestionInjectionTest extends TestCase
{
    public function testInjectedContextNeverReachesTheSystemPrompt(): void
    {
        foreach (PromptInjectionPayloads::all() as $name => $payload) {
            $llm = new RecordingLlmProvider(['tags' => [], 'reasoning' => null]);
            $this->assistant($llm)->suggest($payload, TagScope::Any, new Workspace());

            self::assertStringNotContainsString(
                PromptInjectionPayloads::MARKER,
                $llm->lastSystem(),
                "payload '{$name}' leaked into the system prompt",
            );
            self::assertStringContainsString(PromptInjectionPayloads::MARKER, $llm->lastUser());
        }
    }

    public function testHijackedModelCannotApplyAnInventedTag(): void
    {
        // The model "obeyed" an injection and returned a real tag PLUS a forged one.
        $llm = new RecordingLlmProvider([
            'tags' => ['billing', 'grant-admin-access'],
            'reasoning' => 'ok',
        ]);

        $out = $this->assistant($llm)->suggest(
            PromptInjectionPayloads::INSTRUCTION_OVERRIDE,
            TagScope::Any,
            new Workspace(),
        );

        $applied = array_map(static fn (Tag $t): string => $t->getName(), $out['tags']);
        self::assertSame(['billing'], $applied, 'only real workspace tags may be applied');
        self::assertNotContains('grant-admin-access', $applied);
        self::assertSame(['grant-admin-access'], $out['suggestedNewTags'], 'forged tag is quarantined, not applied');
    }

    private function assistant(RecordingLlmProvider $llm): TagSuggestionAssistant
    {
        $ws = new Workspace();
        $tagRepo = $this->createStub(TagRepository::class);
        $tagRepo->method('findBy')->willReturn([
            (new Tag())->setName('billing')->setScope(TagScope::Any)->setWorkspace($ws),
            (new Tag())->setName('bug')->setScope(TagScope::Any)->setWorkspace($ws),
        ]);

        return new TagSuggestionAssistant($llm, $tagRepo, new AiUsageContext());
    }
}
