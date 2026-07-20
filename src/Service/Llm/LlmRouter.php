<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\Workspace;

/**
 * Per-task-type routing policy behind {@see LlmProviderInterface}. Turns the
 * ambient (feature, workspace) attribution from {@see AiUsageContext} into the
 * concrete provider(s) a call should use — the decision the {@see RoutingLlmProvider}
 * acts on.
 *
 * Resolution order for a call:
 *   1. Privacy lock — `settings.ai.forceLocal === true` forces {@see LlmTier::Local}
 *      for EVERY task-type (data residency). Fail-closed: if the local provider
 *      isn't configured, the call throws rather than silently going to the cloud.
 *   2. Per-feature MODEL pin — `settings.ai.models[<feature>]` names a specific
 *      {@see ModelCatalog} entry (e.g. route the mass triage call to Haiku); its
 *      provider + model are used directly. Beats the tier map (most specific).
 *   3. Per-workspace tier override — `settings.ai.routing[<feature>]` beats the
 *      default map.
 *   4. Default map — {@see self::DEFAULT_TIERS}; unknown/unattributed features
 *      fall through to {@see self::DEFAULT_TIER} (Cloud, i.e. unchanged behaviour).
 *
 * Graceful degradation for NON-privacy workspaces: a Local/LocalFallbackCloud
 * decision (or a pin to an unconfigured provider) with no local provider
 * configured falls back to the cloud provider, so a default deployment (no
 * OLLAMA_API_BASE) behaves exactly as before.
 */
class LlmRouter
{
    /**
     * Volume-heavy, reasoning-light task-types default to local; agentic /
     * outbound-drafting task-types stay on the cloud model (the default tier).
     *
     * @var array<string, LlmTier>
     */
    private const DEFAULT_TIERS = [
        // Classification / extraction / tagging — including the per-inbound-mail
        // "is this a ticket?" mass-call, the single biggest cost driver.
        'triage' => LlmTier::Local,
        'ticket_from_conversation' => LlmTier::Local,
        'tags' => LlmTier::Local,
        // Cheap suggestions: try local, fall back to the cloud model on failure.
        'estimate' => LlmTier::LocalFallbackCloud,
        'reply' => LlmTier::LocalFallbackCloud,
        // Everything else (schedule, absence_intake, command, research, marketing,
        // action-planning, unattributed) → DEFAULT_TIER below.
    ];

    private const DEFAULT_TIER = LlmTier::Cloud;

    /**
     * Feature keys surfaced in the routing settings UI, in display order. Not
     * every assistant sets an attribution key; this is the curated, routable set
     * an admin can override per workspace.
     */
    public const KNOWN_FEATURES = [
        'triage',
        'ticket_from_conversation',
        'tags',
        'estimate',
        'reply',
        'schedule',
        'absence_intake',
        'command',
    ];

    /**
     * Minimum capability class the {@see LlmTier::Cheapest} strategy must respect
     * per feature, so auto-cheapest never drops an agentic / drafting task onto a
     * tiny model. Keyed by feature; unset features floor at 'fast' (no constraint —
     * genuinely cheapest). Ranks: fast < balanced < frontier ({@see self::classRank()}).
     *
     * @var array<string, string>
     */
    private const MIN_CLASS = [
        'schedule' => 'balanced',
        'absence_intake' => 'balanced',
        'command' => 'balanced',
    ];

    public function __construct(
        private readonly OllamaLlmProvider $local,
        private readonly LlmProviderFactory $cloudFactory,
        private readonly AnthropicLlmProvider $anthropic,
        private readonly InfomaniakLlmProvider $infomaniak,
        private readonly ModelCatalog $catalog,
    ) {}

    /** Whether at least one provider (local or cloud) can serve a call. */
    public function isAnyConfigured(): bool
    {
        return $this->local->isConfigured() || $this->cloudFactory->create()->isConfigured();
    }

    /** Whether the local (on-infra) provider is configured. */
    public function isLocalConfigured(): bool
    {
        return $this->local->isConfigured();
    }

    /** Whether a named catalog provider (anthropic/infomaniak/ollama) is configured. */
    public function isProviderConfigured(string $providerName): bool
    {
        return $this->providerFor($providerName)->isConfigured();
    }

    /** The default tier for a feature, ignoring any per-workspace override. */
    public function defaultTierFor(string $feature): LlmTier
    {
        return self::DEFAULT_TIERS[$feature] ?? self::DEFAULT_TIER;
    }

    /**
     * The tier a feature resolves to for a workspace, before provider fallback.
     * Exposed for the routing settings read-model + tests.
     */
    public function tierFor(?string $feature, ?Workspace $workspace): LlmTier
    {
        if ($this->isForceLocal($workspace)) {
            return LlmTier::Local;
        }

        $override = $this->workspaceOverrides($workspace)[$feature] ?? null;
        if ($override instanceof LlmTier) {
            return $override;
        }

        return $feature !== null ? $this->defaultTierFor($feature) : self::DEFAULT_TIER;
    }

    /** The catalog key pinned for a feature in this workspace, if any. */
    public function pinnedModelKey(?string $feature, ?Workspace $workspace): ?string
    {
        if ($feature === null) {
            return null;
        }
        $models = $workspace?->getSettings()['ai']['models'] ?? null;
        $key = \is_array($models) ? ($models[$feature] ?? null) : null;

        return \is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Resolve the provider chain for a call: the primary provider (+ optional
     * per-call model) plus an optional fallback the decorator tries on an
     * {@see LlmException}.
     */
    public function route(?string $feature, ?Workspace $workspace): RoutedCall
    {
        // 1. Privacy lock is absolute: local only, no cloud fallback, fail-closed.
        if ($this->isForceLocal($workspace)) {
            if (!$this->local->isConfigured()) {
                throw new LlmException(
                    'This workspace requires local AI (data residency), but no local model is configured.',
                );
            }

            return new RoutedCall($this->local);
        }

        // 2. Explicit per-feature model pin → that catalog entry's provider + model,
        //    as long as the provider is configured (else fall through to the tier).
        $pinnedKey = $this->pinnedModelKey($feature, $workspace);
        if ($pinnedKey !== null) {
            $entry = $this->catalog->get($pinnedKey);
            if ($entry !== null) {
                $provider = $this->providerFor($entry->provider);
                if ($provider->isConfigured()) {
                    return new RoutedCall($provider, $entry->model);
                }
            }
        }

        // 3./4. Tier (per-workspace override, else default map).
        $cloud = $this->cloudFactory->create();

        return match ($this->tierFor($feature, $workspace)) {
            // Non-privacy: prefer local, but degrade to cloud when unconfigured.
            LlmTier::Local => new RoutedCall($this->local->isConfigured() ? $this->local : $cloud),
            LlmTier::Cloud => new RoutedCall($cloud),
            LlmTier::LocalFallbackCloud => $this->local->isConfigured()
                ? new RoutedCall($this->local, null, $cloud, null)
                : new RoutedCall($cloud),
            // Cheapest available catalog model meeting the feature's quality floor;
            // if none is available (no configured provider), fall back to cloud.
            LlmTier::Cheapest => $this->cheapestFor($feature) ?? new RoutedCall($cloud),
        };
    }

    /**
     * The cheapest *available* catalog model whose capability class meets the
     * feature's floor (agentic tasks stay at least "balanced" so Cheapest never
     * drops them onto a tiny model). Ranked by {@see ModelDefinition::blendedPer1M()};
     * ties keep catalog order. Null when no configured provider offers a qualifying
     * model. Local (Ollama) is intentionally out of scope — it's addressed via the
     * Local tier / forceLocal, not as a priced catalog candidate.
     */
    private function cheapestFor(?string $feature): ?RoutedCall
    {
        $floor = $this->minClassRank($feature);
        $best = null;
        foreach ($this->catalog->all() as $model) {
            if (!$this->isProviderConfigured($model->provider)) {
                continue;
            }
            if ($this->classRank($model->speed) < $floor) {
                continue;
            }
            if ($best === null || $model->blendedPer1M() < $best->blendedPer1M()) {
                $best = $model;
            }
        }

        return $best === null ? null : new RoutedCall($this->providerFor($best->provider), $best->model);
    }

    private function minClassRank(?string $feature): int
    {
        return $this->classRank($feature !== null ? (self::MIN_CLASS[$feature] ?? 'fast') : 'fast');
    }

    private function classRank(string $speed): int
    {
        return match ($speed) {
            'frontier' => 3,
            'balanced' => 2,
            default => 1, // 'fast'
        };
    }

    /** Map a catalog provider name to its concrete provider instance. */
    private function providerFor(string $providerName): LlmProviderInterface
    {
        return match ($providerName) {
            'anthropic' => $this->anthropic,
            'infomaniak' => $this->infomaniak,
            'ollama' => $this->local,
            default => $this->cloudFactory->create(),
        };
    }

    private function isForceLocal(?Workspace $workspace): bool
    {
        return ($workspace?->getSettings()['ai']['forceLocal'] ?? false) === true;
    }

    /**
     * @return array<string, LlmTier>
     */
    private function workspaceOverrides(?Workspace $workspace): array
    {
        $raw = $workspace?->getSettings()['ai']['routing'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $feature => $tier) {
            $parsed = LlmTier::tryFromLoose($tier);
            if (\is_string($feature) && $parsed !== null) {
                $out[$feature] = $parsed;
            }
        }

        return $out;
    }
}
