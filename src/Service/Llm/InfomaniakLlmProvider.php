<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * {@see LlmProviderInterface} backed by Infomaniak AI Tools — a Swiss/EU-hosted,
 * OpenAI-compatible multi-model API over open-source LLMs (Mistral, Llama, Qwen, …).
 *
 * Chosen for its data-governance fit: prompts stay in Europe, aren't recorded and
 * aren't used to train models (FADP/GDPR) — which matters when product/customer
 * data is sent for marketing copy. The wire format is OpenAI chat-completions, so
 * this is a thin HTTP client; the model is env-selectable.
 *
 * Egress is gated exactly like {@see AnthropicLlmProvider}: prompt data only
 * leaves for Infomaniak when the `llm` module is approved (EGRESS_ALLOW).
 */
final class InfomaniakLlmProvider implements LlmProviderInterface
{
    private const DEFAULT_MODEL = 'mistral3';
    // OpenAI-compatible surface: {base}/{productId}/openai/v1/chat/completions.
    private const DEFAULT_API_BASE = 'https://api.infomaniak.com/2/ai';

    private readonly string $apiToken;
    private readonly string $productId;
    private readonly string $model;
    private readonly string $apiBase;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EgressGuard $egress,
        private readonly LlmUsageRecorder $usage,
        ?string $apiToken = null,
        ?string $productId = null,
        ?string $model = null,
        ?string $apiBase = null,
    ) {
        $this->apiToken = (string) $apiToken;
        $this->productId = (string) $productId;
        $this->model = ($model !== null && $model !== '') ? $model : self::DEFAULT_MODEL;
        $this->apiBase = ($apiBase !== null && $apiBase !== '') ? rtrim($apiBase, '/') : self::DEFAULT_API_BASE;
    }

    public function isConfigured(): bool
    {
        return $this->apiToken !== '' && $this->productId !== '';
    }

    public function complete(string $system, string $user, int $maxTokens = 4096): string
    {
        return $this->request($system, $user, $maxTokens, jsonMode: false);
    }

    public function completeJson(string $system, string $user, int $maxTokens = 2048): array
    {
        // Belt-and-braces: instruct via prompt AND request native JSON mode, since
        // open-weight models adhere to strict JSON less reliably than Claude.
        $jsonSystem = $system . "\n\nReturn ONLY a single valid JSON object. No prose, no explanation, "
            . 'no Markdown code fences around it.';

        $raw = $this->request($jsonSystem, $user, $maxTokens, jsonMode: true);
        $decoded = json_decode(self::stripFences($raw), true);

        if (!\is_array($decoded)) {
            throw new LlmException('The model did not return a valid JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function request(string $system, string $user, int $maxTokens, bool $jsonMode): string
    {
        if (!$this->isConfigured()) {
            throw new LlmException('INFOMANIAK_API_TOKEN / INFOMANIAK_PRODUCT_ID are not configured.');
        }
        // Default-deny egress gate: same `llm` module as any other provider.
        if (!$this->egress->isAllowed(EgressModule::Llm)) {
            throw new LlmException('LLM egress not approved (module "llm").');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $url = sprintf('%s/%s/openai/v1/chat/completions', $this->apiBase, rawurlencode($this->productId));

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new LlmException('LLM request failed: ' . $e->getMessage(), previous: $e);
        }

        // OpenAI-shape usage block; account before the content/finish-reason checks.
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $this->usage->record(
            'infomaniak',
            $this->model,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
        );

        $choice = $data['choices'][0] ?? null;
        $finishReason = \is_array($choice) ? ($choice['finish_reason'] ?? null) : null;
        if ($finishReason === 'content_filter') {
            throw new LlmException('The model declined to generate this content.');
        }

        $text = \is_array($choice) ? (string) ($choice['message']['content'] ?? '') : '';
        $text = trim($text);
        if ($text === '') {
            throw new LlmException('The model returned no text.');
        }

        return $text;
    }

    /**
     * Strip a leading/trailing Markdown code fence (```json … ```) the model may
     * wrap the JSON in despite instructions.
     */
    private static function stripFences(string $text): string
    {
        $trimmed = trim($text);
        if (!str_starts_with($trimmed, '```')) {
            return $trimmed;
        }
        $trimmed = preg_replace('/^```[a-zA-Z0-9]*\s*\n?/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\n?```\s*$/', '', $trimmed) ?? $trimmed;

        return trim($trimmed);
    }
}
