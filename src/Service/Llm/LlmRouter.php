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
 *   2. Per-workspace override — `settings.ai.routing[<feature>]` (a {@see LlmTier}
 *      value) beats the default map.
 *   3. Default map — {@see self::DEFAULT_TIERS}; unknown/unattributed features
 *      fall through to {@see self::DEFAULT_TIER} (Cloud, i.e. unchanged behaviour).
 *
 * Graceful degradation for NON-privacy workspaces: a Local/LocalFallbackCloud
 * decision with no local provider configured falls back to the cloud provider,
 * so a default deployment (no OLLAMA_API_BASE) behaves exactly as before.
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

    public function __construct(
        private readonly OllamaLlmProvider $local,
        private readonly LlmProviderFactory $cloudFactory,
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

    /**
     * Resolve to the provider chain for a call: the primary provider plus an
     * optional fallback the decorator tries on an {@see LlmException}.
     *
     * @return array{0: LlmProviderInterface, 1: ?LlmProviderInterface}
     */
    public function route(?string $feature, ?Workspace $workspace): array
    {
        // Privacy lock is absolute: local only, no cloud fallback, fail-closed.
        if ($this->isForceLocal($workspace)) {
            if (!$this->local->isConfigured()) {
                throw new LlmException(
                    'This workspace requires local AI (data residency), but no local model is configured.',
                );
            }

            return [$this->local, null];
        }

        $cloud = $this->cloudFactory->create();

        return match ($this->tierFor($feature, $workspace)) {
            // Non-privacy: prefer local, but degrade to cloud when unconfigured.
            LlmTier::Local => [$this->local->isConfigured() ? $this->local : $cloud, null],
            LlmTier::Cloud => [$cloud, null],
            LlmTier::LocalFallbackCloud => $this->local->isConfigured()
                ? [$this->local, $cloud]
                : [$cloud, null],
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
