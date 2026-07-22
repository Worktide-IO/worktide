<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Workspace;
use App\Repository\ConversationRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProductRepository;
use App\Service\Llm\LlmProviderInterface;

/**
 * Analyses inbound channel data (conversation subjects + bodies, customer
 * industries, existing product catalogue) to identify unmet needs and propose
 * new products or enhancements worth developing. Feeds
 * {@see \App\Entity\AIRecommendation} rows of kind ProductSuggestion.
 *
 * LLM-gated; designed for periodic (daily/weekly) execution via cron.
 */
final class ProductOpportunityAssistant
{
    private const MAX_SAMPLE = 50;
    private const MAX_TEXT = 6000;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly ConversationRepository $conversations,
        private readonly CustomerRepository $customers,
        private readonly ProductRepository $products,
    ) {}

    public function isAvailable(): bool
    {
        return $this->llm->isConfigured();
    }

    public function getModel(): string
    {
        return $this->llm->getModel();
    }

    /**
     * @return list<array{title: string, description: string, rationale: string, targetIndustry: ?string, wouldServeExistingCustomers: bool}>
     */
    public function suggestOpportunities(Workspace $workspace): array
    {
        $context = $this->buildContext($workspace);
        if ($context === null) {
            return [];
        }

        $system = <<<PROMPT
        You are a product strategist for a software agency. You are given a snapshot of:
        - Recent customer conversations (subjects + excerpts from body text)
        - Customer industries and counts
        - The agency's existing product catalogue

        Analyse the conversation data to identify:
        1. Recurring needs, pain points or feature requests that NO existing product addresses
        2. Market gaps where a product would serve multiple existing customers
        3. Industry-specific opportunities worth building a dedicated product for

        For each opportunity, respond with a JSON object in this array format:
        [
          {
            "title": "Short product name or concept (≤ 80 chars)",
            "description": "What the product would do, based on the conversation evidence (2-3 sentences)",
            "rationale": "Why this is worth building — cite specific conversation themes or industry gaps",
            "targetIndustry": "Industry name or null if cross-industry",
            "wouldServeExistingCustomers": true/false
          }
        ]

        Return an empty array [] if no clear opportunities are found. Be specific and evidence-based — do not invent needs not supported by the data.
        PROMPT;

        $raw = $this->llm->completeJson($system, $context);

        if (!\is_array($raw)) {
            return [];
        }

        $opportunities = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $title = \is_string($item['title'] ?? null) ? trim($item['title']) : '';
            if ($title === '') {
                continue;
            }
            $opportunities[] = [
                'title' => mb_substr($title, 0, 80),
                'description' => \is_string($item['description'] ?? null) ? trim($item['description']) : '',
                'rationale' => \is_string($item['rationale'] ?? null) ? trim($item['rationale']) : '',
                'targetIndustry' => \is_string($item['targetIndustry'] ?? null) ? trim($item['targetIndustry']) : null,
                'wouldServeExistingCustomers' => (bool) ($item['wouldServeExistingCustomers'] ?? false),
            ];
        }

        return $opportunities;
    }

    private function buildContext(Workspace $workspace): ?string
    {
        $lines = [];

        // 1. Recent conversations sample
        $recent = $this->conversations->findBy(
            ['workspace' => $workspace],
            ['lastEventAt' => 'DESC'],
            self::MAX_SAMPLE,
        );
        if ($recent !== []) {
            $lines[] = '## Recent Customer Conversations';
            $lines[] = sprintf('(%d sampled from the most recent)', \count($recent));

            foreach ($recent as $convo) {
                $subject = $convo->getSubject() ?? '(no subject)';
                $customerName = $convo->getCustomer()?->getName() ?? 'unknown';
                $industry = $convo->getCustomer()?->getIndustry()?->getName();

                $line = sprintf('- [%s] %s', $customerName, $subject);
                if ($industry !== null && $industry !== '') {
                    $line .= sprintf(' (industry: %s)', $industry);
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }

        // 2. Customer industry breakdown
        $allCustomers = $this->customers->findBy(['workspace' => $workspace]);
        $industries = [];
        foreach ($allCustomers as $c) {
            $name = $c->getIndustry()?->getName();
            if (\is_string($name) && $name !== '') {
                $industries[$name] = ($industries[$name] ?? 0) + 1;
            }
        }
        arsort($industries);
        if ($industries !== []) {
            $lines[] = '## Customer Industries';
            foreach (\array_slice($industries, 0, 15, true) as $name => $count) {
                $lines[] = sprintf('- %s: %d customer(s)', $name, $count);
            }
            $lines[] = '';
        }

        // 3. Existing product catalogue
        $products = $this->products->findBy(['workspace' => $workspace], ['name' => 'ASC']);
        if ($products !== []) {
            $lines[] = '## Existing Product Catalogue';
            foreach ($products as $p) {
                $desc = $p->getDescription();
                $line = sprintf('- %s (%s)', $p->getName(), $p->getType()->value);
                if ($desc !== null && trim($desc) !== '') {
                    $line .= ': ' . $desc;
                }
                $lines[] = $line;
            }
        }

        $text = implode("\n", $lines);

        return mb_strlen($text) > 10 ? mb_substr($text, 0, self::MAX_TEXT) : null;
    }
}
