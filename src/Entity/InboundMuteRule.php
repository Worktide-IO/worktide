<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\InboundRuleCombinator;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\InboundMuteRuleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A workspace rule that suppresses a KIND of inbound message (e.g. 2FA /
 * verification-code mail) so it no longer clutters the inbox or triggers
 * automation/AI.
 *
 * Thunderbird-style declarative matcher: a list of {@see conditions} (each
 * `{field, operator, value}`) combined with AND/OR ({@see combinator}). This
 * covers precise cases a single field can't — e.g. sender=hetzner AND subject
 * contains "Verification Code" (mute only the 2FA, not every Hetzner mail).
 * Genuinely procedural / stateful / external-data logic stays in n8n
 * (process-then-hide via the automation apply endpoint).
 *
 * "Suppress" does NOT delete: {@see \App\Service\Inbound\InboundMuteMatcher}
 * flags the matched {@see Conversation} with `mutedAt` (kept + searchable, out
 * of the default inbox, reachable via the "Ignoriert" view). Reversible.
 *
 * ROADMAP: today the only action is "hide" (mute). A follow-up generalises this
 * into a full inbound-rule engine with multiple action types (tag / set status /
 * assign) and trigger-timing, matching Thunderbird's action list.
 */
#[ORM\Entity(repositoryClass: InboundMuteRuleRepository::class)]
#[ORM\Table(name: 'inbound_mute_rules')]
#[ORM\Index(name: 'inbound_mute_rule_workspace_idx', columns: ['workspace_id', 'is_enabled'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'InboundMuteRule',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['isEnabled'])]
class InboundMuteRule
{
    use EntityIdTrait;
    use TimestampableTrait;
    use VersionedTrait;
    use AuditableTrait;
    use WorkspaceScopedTrait;

    #[ORM\Column(length: 8, enumType: InboundRuleCombinator::class, options: ['default' => 'and'])]
    private InboundRuleCombinator $combinator = InboundRuleCombinator::And;

    /**
     * Conditions, each `{field: InboundRuleField, operator: InboundRuleOperator,
     * value: string}`. Empty = matches nothing (never mute-all).
     *
     * @var list<array{field: string, operator: string, value: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $conditions = [];

    #[ORM\Column]
    private bool $isEnabled = true;

    /** Server-managed: how many messages this rule has muted (observability). */
    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $matchCount = 0;

    #[ApiProperty(writable: false)]
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMatchedAt = null;

    public function getCombinator(): InboundRuleCombinator { return $this->combinator; }
    public function setCombinator(InboundRuleCombinator $c): self { $this->combinator = $c; return $this; }

    /** @return list<array{field: string, operator: string, value: string}> */
    public function getConditions(): array { return $this->conditions; }

    /** @param list<array{field: string, operator: string, value: string}> $conditions */
    public function setConditions(array $conditions): self { $this->conditions = array_values($conditions); return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $v): self { $this->isEnabled = $v; return $this; }

    public function getMatchCount(): int { return $this->matchCount; }
    public function setMatchCount(int $n): self { $this->matchCount = $n; return $this; }

    public function getLastMatchedAt(): ?\DateTimeImmutable { return $this->lastMatchedAt; }
    public function setLastMatchedAt(?\DateTimeImmutable $t): self { $this->lastMatchedAt = $t; return $this; }

    public function registerHit(\DateTimeImmutable $when): void
    {
        ++$this->matchCount;
        $this->lastMatchedAt = $when;
    }
}
