<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Llm\LlmProviderInterface;

/**
 * Test double for {@see LlmProviderInterface} that (a) RECORDS every
 * (system, user) pair it is called with and (b) returns a preset response —
 * modelling a fully hijacked model that "obeyed" an injection.
 *
 * The two halves the prompt-injection suite needs:
 *  - recording lets a test assert untrusted content stayed in the user message
 *    and never contaminated the system prompt (prompt hygiene);
 *  - the preset response lets a test feed the agent a MALICIOUS model output and
 *    assert the deterministic validation layer neutralises it (output guardrail).
 *
 * No network, no EgressGuard — pure, deterministic, CI-safe.
 */
final class RecordingLlmProvider implements LlmProviderInterface
{
    /** @var list<array{kind: string, system: string, user: string, maxTokens: int}> */
    public array $calls = [];

    /** @param array<string, mixed> $json response returned by completeJson() */
    public function __construct(
        private readonly array $json = [],
        private readonly string $text = '',
        private readonly bool $configured = true,
        private readonly string $model = 'recording-test',
    ) {}

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function complete(string $system, string $user, int $maxTokens = 4096): string
    {
        $this->calls[] = ['kind' => 'complete', 'system' => $system, 'user' => $user, 'maxTokens' => $maxTokens];

        return $this->text;
    }

    /** @return array<string, mixed> */
    public function completeJson(string $system, string $user, int $maxTokens = 2048): array
    {
        $this->calls[] = ['kind' => 'completeJson', 'system' => $system, 'user' => $user, 'maxTokens' => $maxTokens];

        return $this->json;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /** The system prompt of the most recent call ('' if never called). */
    public function lastSystem(): string
    {
        return $this->calls === [] ? '' : $this->calls[array_key_last($this->calls)]['system'];
    }

    /** The user message of the most recent call ('' if never called). */
    public function lastUser(): string
    {
        return $this->calls === [] ? '' : $this->calls[array_key_last($this->calls)]['user'];
    }

    public function called(): bool
    {
        return $this->calls !== [];
    }
}
