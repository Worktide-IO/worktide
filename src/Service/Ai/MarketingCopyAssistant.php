<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Channels\AdapterRegistry;
use App\Entity\Product;
use App\Repository\ChannelRepository;
use App\Service\Llm\LlmProviderInterface;

/**
 * Produces marketing suggestions for one catalog {@see Product} (or service):
 * a short summary plus a ready-to-publish post variant per social network,
 * each within that network's character limit. Mirrors
 * {@see TicketTriageAssistant} / {@see \App\Service\Social\SocialPostAiAssistant}:
 * suggestions only, never published here — a human accepts the recommendation,
 * which materialises a Draft {@see \App\Entity\SocialPost} (via
 * {@see RecommendationApplier}) that still goes through the normal approval gate.
 *
 * The model may only target networks we actually support: it's given the
 * workspace's connected social channels (or, if none yet, every social adapter
 * we know), and any variant for an unknown network is dropped in validation.
 */
final class MarketingCopyAssistant
{
    private const MAX_TEXT = 4000;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly AdapterRegistry $registry,
        private readonly ChannelRepository $channels,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    /** The model that produced a suggestion — stored on the recommendation for provenance. */
    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * @return array{suggestion: array{summary: string, variants: list<array{adapterCode: string, network: string, body: string}>}, reasoning: ?string}
     */
    public function draftSocialPosts(Product $product): array
    {
        $adapters = $this->availableAdapters($product);

        $system = $this->systemPrompt($adapters);
        $user = $this->buildProductContext($product);

        $raw = $this->llm->completeJson($system, $user);

        $suggestion = [
            'summary' => $this->cleanSummary($raw['summary'] ?? null),
            'variants' => $this->matchVariants($raw['variants'] ?? null, $adapters),
        ];

        return ['suggestion' => $suggestion, 'reasoning' => $this->cleanReasoning($raw['reasoning'] ?? null)];
    }

    /**
     * The networks a draft may target: the workspace's connected social channels
     * if any, otherwise every social adapter we support (so useful copy is still
     * generated before any channel is connected).
     *
     * @return array<string, array{label: string, maxLength: int}> keyed by adapterCode
     */
    private function availableAdapters(Product $product): array
    {
        $codes = [];
        foreach ($this->channels->findEnabledSocial($product->getWorkspace()) as $channel) {
            $codes[$channel->getAdapterCode()] = true;
        }
        if ($codes === []) {
            foreach ($this->registry->knownSocialCodes() as $code) {
                $codes[$code] = true;
            }
        }

        $out = [];
        foreach (array_keys($codes) as $code) {
            $adapter = $this->registry->trySocial($code);
            if ($adapter === null) {
                continue;
            }
            $out[$code] = ['label' => $adapter->getLabel(), 'maxLength' => $adapter->maxLength()];
        }

        return $out;
    }

    private function buildProductContext(Product $product): string
    {
        $parts = [
            'Name: ' . $product->getName(),
            'Type: ' . $product->getType()->value,
        ];
        if (($category = $product->getCategory()) !== null && trim($category) !== '') {
            $parts[] = 'Category: ' . $category;
        }
        if (($description = $product->getDescription()) !== null && trim($description) !== '') {
            $parts[] = 'Description: ' . $description;
        }
        $latest = $product->getLatestVersion();
        if ($latest !== null) {
            $parts[] = 'Latest version: ' . $latest->getVersion();
            $notes = $latest->getReleaseNotes();
            if ($notes !== null && trim($notes) !== '') {
                $parts[] = 'Release notes: ' . $notes;
            }
        }

        return $this->cap(implode("\n", $parts));
    }

    /**
     * @param array<string, array{label: string, maxLength: int}> $adapters
     */
    private function systemPrompt(array $adapters): string
    {
        if ($adapters === []) {
            $networkList = '(no social networks configured)';
        } else {
            $lines = [];
            foreach ($adapters as $code => $meta) {
                $lines[] = sprintf('- adapterCode "%s" → %s (max %d characters)', $code, $meta['label'], $meta['maxLength']);
            }
            $networkList = implode("\n", $lines);
        }

        return <<<PROMPT
        You are a marketing copywriter for a software agency. Given one of the agency's own
        offerings (a product or service), write an engaging, ready-to-publish social media post
        for each of the networks listed below. Be accurate and concrete; do not invent features
        or claims not supported by the given details.

        Available networks:
        {$networkList}

        Respond as a JSON object with these keys:
        - "summary": one or two sentences describing the marketing angle, in the offering's language.
        - "variants": an array of objects, one per network you write for, each with:
            - "adapterCode": exactly one of the adapterCodes listed above.
            - "body": the post text, within that network's character limit, following its conventions
              (natural hashtags/mentions where they fit; concise and engaging).
        - "reasoning": one short sentence explaining the chosen angle.
        PROMPT;
    }

    // -- validation helpers ---------------------------------------------------

    /**
     * Keep only variants for networks we support; resolve by adapterCode, else by
     * network label; cap each body to that network's hard limit; drop empties and
     * duplicates (first variant per network wins).
     *
     * @param array<string, array{label: string, maxLength: int}> $adapters
     * @return list<array{adapterCode: string, network: string, body: string}>
     */
    private function matchVariants(mixed $value, array $adapters): array
    {
        if (!\is_array($value) || $adapters === []) {
            return [];
        }

        $seen = [];
        $out = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $code = $this->resolveAdapterCode($item['adapterCode'] ?? null, $item['network'] ?? null, $adapters);
            if ($code === null || isset($seen[$code])) {
                continue;
            }
            $body = \is_string($item['body'] ?? null) ? trim((string) $item['body']) : '';
            if ($body === '') {
                continue;
            }
            $maxLength = $adapters[$code]['maxLength'];
            if (mb_strlen($body) > $maxLength) {
                $body = rtrim(mb_substr($body, 0, $maxLength));
            }
            $seen[$code] = true;
            $out[] = ['adapterCode' => $code, 'network' => $adapters[$code]['label'], 'body' => $body];
        }

        return $out;
    }

    /**
     * @param array<string, array{label: string, maxLength: int}> $adapters
     */
    private function resolveAdapterCode(mixed $code, mixed $network, array $adapters): ?string
    {
        if (\is_string($code) && isset($adapters[$code])) {
            return $code;
        }
        if (\is_string($network)) {
            $needle = mb_strtolower(trim($network));
            foreach ($adapters as $adapterCode => $meta) {
                if (mb_strtolower($meta['label']) === $needle) {
                    return $adapterCode;
                }
            }
        }

        return null;
    }

    private function cleanSummary(mixed $value): string
    {
        $s = \is_string($value) ? trim($value) : '';

        return mb_substr($s, 0, 1000);
    }

    private function cleanReasoning(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s === '' ? null : mb_substr($s, 0, 1000);
    }

    private function cap(string $text): string
    {
        return mb_substr($text, 0, self::MAX_TEXT);
    }
}
