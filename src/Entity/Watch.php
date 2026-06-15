<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Enum\WatchableTarget;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\WatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A user subscribed to notifications on a polymorphic target.
 *
 * Modelled identical to {@see Comment}: `target` enum + `targetId` UUID,
 * one row per (workspace, target, targetId, user) tuple — enforced by a
 * unique index so subscribe is idempotent (POST twice is fine, never
 * produces duplicates).
 *
 * Inspired by Redmine's `watchers` table (polymorphic `watchable_type` +
 * `watchable_id`, see ~/ddev/bluemine/symfony/src/Entity/Watcher.php).
 *
 * Notifications themselves are out of scope here — this entity only
 * keeps the subscription list. A future MessageHandler will iterate the
 * watchers when a tracked DomainEvent fires.
 *
 * GET-collection is permissive (any ROLE_USER can list to render the
 * "Watching" badge on a card); mutations go through the WatchActions
 * controller's toggle endpoint so the route IS the authorisation
 * (always operates on the current user's own subscriptions).
 */
#[ORM\Entity(repositoryClass: WatchRepository::class)]
#[ORM\Table(name: 'watches')]
#[ORM\UniqueConstraint(
    name: 'watch_unique',
    columns: ['workspace_id', 'target', 'target_id', 'user_id'],
)]
#[ORM\Index(name: 'watch_target_idx', columns: ['target', 'target_id'])]
#[ORM\Index(name: 'watch_user_idx', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'Watch',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_USER') and object.getUser() == user"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'target' => 'exact',
    'targetId' => 'exact',
    'user' => 'exact',
])]
class Watch
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\Column(length: 16, enumType: WatchableTarget::class)]
    private WatchableTarget $target;

    #[ORM\Column(type: 'uuid')]
    private Uuid $targetId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    public function getTarget(): WatchableTarget { return $this->target; }
    public function setTarget(WatchableTarget $t): self { $this->target = $t; return $this; }

    public function getTargetId(): Uuid { return $this->targetId; }
    public function setTargetId(Uuid $id): self { $this->targetId = $id; return $this; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }
}
