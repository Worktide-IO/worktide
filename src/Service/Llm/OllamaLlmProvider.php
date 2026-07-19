<?php

declare(strict_types=1);

namespace App\Service\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * {@see LlmProviderInterface} backed by an on-infra, OpenAI-compatible local
 * inference server — Ollama for dev/small workspaces, vLLM for production
 * throughput (both expose `/v1/chat/completions`). Selected by the
 * {@see LlmRouter} for volume-heavy, reasoning-light task-types, and *forced*
 * for privacy workspaces (`settings.ai.forceLocal`) so customer data never
 * leaves the infrastructure.
 *
 * Two deliberate differences from {@see AnthropicLlmProvider}/{@see InfomaniakLlmProvider}:
 *
 *   1. NO {@see \App\Egress\EgressGuard} gate. The whole point of local serving
 *      is data residency — the prompt stays on-infra, so this is not egress.
 *      The base URL is operator-configured via env (`OLLAMA_API_BASE`), never
 *      user/admin-supplied through the API, so there is no SSRF surface to gate
 *      (same trust class as the DB DSN). Web-research/fetch calls an agent makes
 *      still run through the EgressGuard — only the reasoning is local.
 *   2. NO {@see LlmBudgetGuard}. Local inference is free, so it must not be
 *      blocked by a workspace's monthly *spend* cap (that would wrongly stop a
 *      privacy workspace from doing any AI once its historic cloud spend maxed
 *      out). Usage is still logged (at cost 0 — no price entry) for volume stats.
 */
final class OllamaLlmProvider implements LlmProviderInterface
{
    private const DEFAULT_MODEL = 'llama3';

    private readonly string $apiBase;
    private readonly string $apiKey;
    private readonly string $model;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LlmUsageRecorder $usage,
        ?string $apiBase = null,
        ?string $apiKey = null,
        ?string $model = null,
    ) {
        $this->apiBase = $apiBase !== null ? rtrim($apiBase, '/') : '';
        $this->apiKey = (string) $apiKey;
        $this->model = ($model !== null && $model !== '') ? $model : self::DEFAULT_MODEL;
    }

    public function isConfigured(): bool
    {
        return $this->apiBase !== '';
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
            throw new LlmException('OLLAMA_API_BASE is not configured.');
        }
        // No egress gate + no budget guard here — see the class docblock: local
        // inference is on-infra (not egress) and free (not subject to the spend cap).

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

        $headers = ['Content-Type' => 'application/json'];
        // Some deployments front the server with an auth proxy; send a bearer if set.
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        try {
            $response = $this->httpClient->request('POST', $this->apiBase . '/chat/completions', [
                'headers' => $headers,
                'json' => $payload,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new LlmException('Local LLM request failed: ' . $e->getMessage(), previous: $e);
        }

        // OpenAI-shape usage block; account before the content/finish-reason checks.
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $this->usage->record(
            'ollama',
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
