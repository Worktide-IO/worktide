<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\LlmUsageLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per LLM call: the token counts + a computed cost, tagged with the
 * feature that triggered it and (where attributed) the workspace. Written by
 * {@see \App\Service\Llm\LlmUsageRecorder} from inside the providers, so every
 * call through {@see \App\Service\Llm\LlmProviderInterface} is accounted for.
 *
 * The foundation for the admin cost/quota dashboard: aggregate `costMicros` by
 * workspace / feature / model / time. Deliberately NOT an API resource yet — the
 * scoped read + aggregation surface lands with the dashboard.
 *
 * `workspace` and `feature` are nullable so a call whose caller didn't set the
 * {@see \App\Service\Llm\AiUsageContext} is still recorded (unattributed) rather
 * than lost.
 */
#[ORM\Entity(repositoryClass: LlmUsageLogRepository::class)]
#[ORM\Table(name: 'llm_usage_logs')]
#[ORM\Index(name: 'llm_usage_workspace_created_idx', columns: ['workspace_id', 'created_at'])]
#[ORM\Index(name: 'llm_usage_feature_idx', columns: ['feature'])]
#[ORM\Index(name: 'llm_usage_created_idx', columns: ['created_at'])]
#[ORM\HasLifecycleCallbacks]
class LlmUsageLog
{
    use EntityIdTrait;
    use TimestampableTrait;

    /** Null = usage not attributed to a workspace (caller didn't set the context). */
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Workspace $workspace = null;

    /** The feature/assistant that triggered the call, e.g. `estimate`, `triage`, `reply`. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $feature = null;

    /** Provider key: `anthropic` | `infomaniak`. */
    #[ORM\Column(length: 20)]
    private string $provider;

    #[ORM\Column(length: 80)]
    private string $model;

    #[ORM\Column]
    private int $inputTokens = 0;

    #[ORM\Column]
    private int $outputTokens = 0;

    /** Computed cost in micro-USD (USD × 1e6); 0 when the model has no price entry. */
    #[ORM\Column(type: 'bigint')]
    private int $costMicros = 0;

    /** Whether the call succeeded (a failed call still records what it consumed, if known). */
    #[ORM\Column]
    private bool $ok = true;

    public function getWorkspace(): ?Workspace { return $this->workspace; }
    public function setWorkspace(?Workspace $workspace): self { $this->workspace = $workspace; return $this; }

    public function getFeature(): ?string { return $this->feature; }
    public function setFeature(?string $feature): self { $this->feature = $feature; return $this; }

    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): self { $this->provider = $provider; return $this; }

    public function getModel(): string { return $this->model; }
    public function setModel(string $model): self { $this->model = $model; return $this; }

    public function getInputTokens(): int { return $this->inputTokens; }
    public function setInputTokens(int $t): self { $this->inputTokens = $t; return $this; }

    public function getOutputTokens(): int { return $this->outputTokens; }
    public function setOutputTokens(int $t): self { $this->outputTokens = $t; return $this; }

    public function getCostMicros(): int { return $this->costMicros; }
    public function setCostMicros(int $c): self { $this->costMicros = $c; return $this; }

    public function isOk(): bool { return $this->ok; }
    public function setOk(bool $ok): self { $this->ok = $ok; return $this; }
}
