<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\MissionMessageRole;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ResearchMissionMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One turn of the clarification dialog on a {@see ResearchMission}: the agent
 * asks questions to sharpen the brief, the user answers. `question` optionally
 * carries structured options for quick answers.
 */
#[ORM\Entity(repositoryClass: ResearchMissionMessageRepository::class)]
#[ORM\Table(name: 'research_mission_messages')]
#[ORM\Index(name: 'research_mission_message_mission_idx', columns: ['mission_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ResearchMissionMessage',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['workspace' => 'exact', 'mission' => 'exact', 'role' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class ResearchMissionMessage
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ResearchMission $mission;

    #[ORM\Column(length: 8, enumType: MissionMessageRole::class)]
    private MissionMessageRole $role = MissionMessageRole::User;

    #[ORM\Column(type: 'text')]
    private string $content = '';

    /** Optional structured question (e.g. {options: [...]}) for quick answers. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $question = null;

    public function getMission(): ResearchMission { return $this->mission; }
    public function setMission(ResearchMission $mission): self { $this->mission = $mission; return $this; }

    public function getRole(): MissionMessageRole { return $this->role; }
    public function setRole(MissionMessageRole $role): self { $this->role = $role; return $this; }

    public function getContent(): string { return $this->content; }
    public function setContent(string $content): self { $this->content = $content; return $this; }

    /** @return array<string, mixed>|null */
    public function getQuestion(): ?array { return $this->question; }
    /** @param array<string, mixed>|null $question */
    public function setQuestion(?array $question): self { $this->question = $question; return $this; }
}
