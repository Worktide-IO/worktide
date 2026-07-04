<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Customer;
use App\Entity\CustomerProduct;
use App\Service\Llm\LlmProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Drafts a personalized upgrade/upsell outreach EMAIL for one {@see Customer}:
 * it inspects the customer's {@see CustomerProduct} rows, finds those running a
 * version older than the product's latest, and asks the LLM for a friendly
 * subject + body highlighting what's new. Suggestions only — a human accepts the
 * recommendation, which materialises an OutboundMessage draft (via
 * {@see RecommendationApplier}); nothing is sent here. Mirrors
 * {@see TicketTriageAssistant} / {@see MarketingCopyAssistant}.
 */
final class UpgradeOutreachAssistant
{
    private const MAX_TEXT = 4000;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly EntityManagerInterface $em,
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
     * @return array{suggestion: array{subject: string, body: string, outdatedProducts: list<array{product: string, currentVersion: string, latestVersion: string}>}, reasoning: ?string}
     */
    public function draftOutreach(Customer $customer): array
    {
        $outdated = $this->outdatedProducts($customer);

        $raw = $this->llm->completeJson($this->systemPrompt(), $this->buildContext($customer, $outdated));

        $suggestion = [
            'subject' => $this->clean($raw['subject'] ?? null, 200),
            'body' => $this->clean($raw['body'] ?? null, self::MAX_TEXT),
            'outdatedProducts' => array_map(
                static fn (array $p): array => [
                    'product' => $p['product'],
                    'currentVersion' => $p['currentVersion'],
                    'latestVersion' => $p['latestVersion'],
                ],
                $outdated,
            ),
        ];

        return ['suggestion' => $suggestion, 'reasoning' => $this->cleanReasoning($raw['reasoning'] ?? null)];
    }

    /**
     * The customer's products whose installed version is not the product's
     * latest. Only versioned products with both a current and a latest version
     * qualify (services are versionless and skipped).
     *
     * @return list<array{product: string, currentVersion: string, latestVersion: string, releaseNotes: string}>
     */
    private function outdatedProducts(Customer $customer): array
    {
        /** @var list<CustomerProduct> $rows */
        $rows = $this->em->getRepository(CustomerProduct::class)->findBy(['customer' => $customer]);

        $out = [];
        foreach ($rows as $cp) {
            $product = $cp->getProduct();
            $current = $cp->getProductVersion();
            $latest = $product->getLatestVersion();
            if ($current === null || $latest === null) {
                continue;
            }
            if ($current->getId()?->toRfc4122() === $latest->getId()?->toRfc4122()) {
                continue; // already up to date
            }
            $out[] = [
                'product' => $product->getName(),
                'currentVersion' => $current->getVersion(),
                'latestVersion' => $latest->getVersion(),
                'releaseNotes' => mb_substr(trim((string) $latest->getReleaseNotes()), 0, 800),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{product: string, currentVersion: string, latestVersion: string, releaseNotes: string}> $outdated
     */
    private function buildContext(Customer $customer, array $outdated): string
    {
        $name = trim($customer->getName());
        $parts = ['Customer: ' . ($name !== '' ? $name : '(unnamed)')];

        if ($outdated === []) {
            $parts[] = 'This customer is on the latest version of every product they use. '
                . 'Write a short, warm check-in email instead of an upgrade pitch.';
        } else {
            $parts[] = 'The customer runs these products on outdated versions:';
            foreach ($outdated as $p) {
                $line = sprintf('- %s: currently %s, latest is %s.', $p['product'], $p['currentVersion'], $p['latestVersion']);
                if ($p['releaseNotes'] !== '') {
                    $line .= " What's new: " . $p['releaseNotes'];
                }
                $parts[] = $line;
            }
        }

        return $this->cap(implode("\n", $parts));
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
        You are a customer-success copywriter for a software agency. Write a personalized,
        friendly upgrade-outreach EMAIL to a customer who runs outdated versions of the products
        they use. Highlight the concrete value of upgrading (based on the release notes given),
        keep it concise and non-pushy, and close with a clear, low-pressure call to action to
        reply or book an upgrade. Write in the customer's likely language (default German).

        Respond as a JSON object with these keys:
        - "subject": a concise, specific email subject line.
        - "body": the email body as plain text (a short greeting, 1–3 short paragraphs, a sign-off).
          Address the customer by name if one is given. Do not invent versions or features.
        - "reasoning": one short sentence explaining the chosen angle.
        PROMPT;
    }

    // -- validation helpers ---------------------------------------------------

    private function clean(mixed $value, int $max): string
    {
        $s = \is_string($value) ? trim($value) : '';

        return mb_substr($s, 0, $max);
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
