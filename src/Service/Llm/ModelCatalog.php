<?php

declare(strict_types=1);

namespace App\Service\Llm;

/**
 * The central, curated list of AI models across providers — the "model hub" the
 * routing UI lists and lets an admin assign per task-type. It is the single
 * source of truth for prices (feeding {@see LlmPricing}) and for the
 * data-processing/GDPR posture ({@see ModelResidency}) shown per model.
 *
 * Cloud + EU models are listed here because those are the ones worth *pinning*
 * per feature (the cost lever: send the high-volume triage call to Haiku instead
 * of Opus). The local (Ollama) model is deployment-bound (OLLAMA_MODEL) and stays
 * addressed via the tier system, not as a catalog pin — so it is intentionally
 * absent here; its usage simply logs at cost 0 (no price entry).
 *
 * Code-defined + curated on purpose: a DB-editable catalog is a later step.
 */
final class ModelCatalog
{
    /** @var list<ModelDefinition> */
    private readonly array $models;

    public function __construct()
    {
        $this->models = [
            new ModelDefinition('anthropic:claude-opus-4-8', 'anthropic', 'claude-opus-4-8', 'Claude Opus 4.8', 15.0, 75.0, ModelResidency::Us, 'frontier'),
            new ModelDefinition('anthropic:claude-opus-4-7', 'anthropic', 'claude-opus-4-7', 'Claude Opus 4.7', 15.0, 75.0, ModelResidency::Us, 'frontier'),
            new ModelDefinition('anthropic:claude-sonnet-5', 'anthropic', 'claude-sonnet-5', 'Claude Sonnet 5', 3.0, 15.0, ModelResidency::Us, 'balanced'),
            new ModelDefinition('anthropic:claude-fable-5', 'anthropic', 'claude-fable-5', 'Claude Fable 5', 3.0, 15.0, ModelResidency::Us, 'balanced'),
            new ModelDefinition('anthropic:claude-haiku-4-5', 'anthropic', 'claude-haiku-4-5-20251001', 'Claude Haiku 4.5', 1.0, 5.0, ModelResidency::Us, 'fast'),
            new ModelDefinition('infomaniak:mistral3', 'infomaniak', 'mistral3', 'Mistral 3 · Infomaniak (EU)', 0.20, 0.60, ModelResidency::Eu, 'balanced'),
            new ModelDefinition('infomaniak:llama3', 'infomaniak', 'llama3', 'Llama 3 · Infomaniak (EU)', 0.20, 0.60, ModelResidency::Eu, 'balanced'),
            new ModelDefinition('infomaniak:qwen', 'infomaniak', 'qwen', 'Qwen · Infomaniak (EU)', 0.20, 0.60, ModelResidency::Eu, 'balanced'),
        ];
    }

    /** @return list<ModelDefinition> */
    public function all(): array
    {
        return $this->models;
    }

    /** Resolve a catalog entry by its stable key (e.g. `anthropic:claude-haiku-4-5`). */
    public function get(string $key): ?ModelDefinition
    {
        foreach ($this->models as $m) {
            if ($m->key === $key) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Resolve by raw provider model id (e.g. `claude-opus-4-8`), for pricing the
     * usage log — which records the model id, not the catalog key. First match
     * wins (model ids are effectively provider-unique).
     */
    public function byModelId(string $modelId): ?ModelDefinition
    {
        foreach ($this->models as $m) {
            if ($m->model === $modelId) {
                return $m;
            }
        }

        return null;
    }
}
