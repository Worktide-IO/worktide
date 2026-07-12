<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Enum\TagScope;
use App\Entity\Tag;
use App\Entity\Workspace;
use App\Repository\TagRepository;
use App\Service\Llm\LlmProviderInterface;

/**
 * On-demand tag suggestions for any taggable record — or for a not-yet-saved
 * draft (the caller passes the draft text directly).
 *
 * Mirrors the tag half of {@see TicketTriageAssistant}: the model is shown the
 * workspace's REAL tags in the record's scope (+ workspace-wide "Any") and told
 * to prefer them; names it invents are surfaced separately as `suggestedNewTags`
 * for the operator to create. Suggestion-only — nothing is applied here.
 */
final class TagSuggestionAssistant
{
    private const MAX_CONTEXT = 4000;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly TagRepository $tags,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    /** The model that produced a suggestion — returned for provenance. */
    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * @return array{tags: list<Tag>, suggestedNewTags: list<string>, reasoning: ?string}
     */
    public function suggest(string $context, TagScope $scope, Workspace $workspace): array
    {
        $context = trim($context);
        if ($context === '') {
            return ['tags' => [], 'suggestedNewTags' => [], 'reasoning' => null];
        }

        // The real tags the model may pick from: the record's own scope + "Any".
        $scoped = array_values(array_filter(
            $this->tags->findBy(['workspace' => $workspace]),
            static fn (Tag $t): bool => \in_array($t->getScope(), [$scope, TagScope::Any], true),
        ));
        $byLowerName = [];
        foreach ($scoped as $tag) {
            $byLowerName[mb_strtolower($tag->getName())] = $tag;
        }
        $tagNames = array_map(static fn (Tag $t): string => $t->getName(), $scoped);

        $raw = $this->llm->completeJson(
            $this->systemPrompt($tagNames),
            mb_substr($context, 0, self::MAX_CONTEXT),
        );

        /** @var array<string, Tag> $matched keyed by lowercase name for dedupe */
        $matched = [];
        /** @var array<string, string> $unknown keyed by lowercase, value = original */
        $unknown = [];
        foreach ((array) ($raw['tags'] ?? []) as $item) {
            if (!\is_string($item)) {
                continue;
            }
            $needle = mb_strtolower(trim($item));
            if ($needle === '') {
                continue;
            }
            if (isset($byLowerName[$needle])) {
                $matched[$needle] = $byLowerName[$needle];
            } else {
                $unknown[$needle] ??= trim($item);
            }
        }

        return [
            'tags' => array_values($matched),
            'suggestedNewTags' => array_values($unknown),
            'reasoning' => $this->cleanReasoning($raw['reasoning'] ?? null),
        ];
    }

    /**
     * @param list<string> $tagNames
     */
    private function systemPrompt(array $tagNames): string
    {
        $tagList = $tagNames === [] ? '(none configured yet)' : implode(', ', $tagNames);

        return <<<PROMPT
        You are a tagging assistant. Given a record's text, propose the most relevant tags used to
        organise and filter it. Be concise and factual; never invent details about the record.

        Respond as a JSON object with these keys:
        - "tags": an array of the most relevant tag names. STRONGLY PREFER the workspace's existing
          tags: {$tagList}. You MAY additionally propose a few well-formed NEW tag names (short,
          lowercase, no punctuation) only when no existing tag fits — these are offered to the user
          to create, not applied automatically. Return an empty array if nothing fits.
        - "reasoning": one short sentence explaining the choice, in the record's own language.
        PROMPT;
    }

    private function cleanReasoning(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s === '' ? null : mb_substr($s, 0, 1000);
    }
}
