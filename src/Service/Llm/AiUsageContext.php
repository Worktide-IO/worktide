<?php

declare(strict_types=1);

namespace App\Service\Llm;

use App\Entity\Workspace;

/**
 * Ambient, mutable attribution for the current LLM call: which feature triggered
 * it and for which workspace. Assistants set this right before calling the
 * provider (the assistant *is* the feature and holds the entity → workspace);
 * the provider reads it when recording usage. Request/worker-scoped — the
 * ai_agents worker processes one message at a time (--limit=1), so there is no
 * cross-call bleed. Defaults to null (unattributed) so a call that forgot to set
 * it is still counted in totals, just without feature/workspace.
 */
final class AiUsageContext
{
    private ?string $feature = null;
    private ?Workspace $workspace = null;

    public function set(?string $feature, ?Workspace $workspace): void
    {
        $this->feature = $feature;
        $this->workspace = $workspace;
    }

    public function clear(): void
    {
        $this->feature = null;
        $this->workspace = null;
    }

    public function getFeature(): ?string
    {
        return $this->feature;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }
}
