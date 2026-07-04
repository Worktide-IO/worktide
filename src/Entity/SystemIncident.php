<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\IncidentKind;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\SystemIncidentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * An incident or maintenance window on a monitored {@see CustomerSystem} — the
 * "Vorfälle & Wartung" list on the portal Monitoring screen. An OPEN incident
 * (resolvedAt = null) sets the system's live status (Outage → Störung,
 * Degraded → Langsam, Maintenance → Wartung). Outage/Degraded are opened and
 * resolved automatically by the probe command; Maintenance is scheduled by staff.
 */
#[ORM\Entity(repositoryClass: SystemIncidentRepository::class)]
#[ORM\Table(name: 'system_incidents')]
#[ORM\Index(name: 'system_incident_system_idx', columns: ['system_id'])]
#[ORM\Index(name: 'system_incident_open_idx', columns: ['system_id', 'resolved_at'])]
#[ORM\HasLifecycleCallbacks]
class SystemIncident
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerSystem $system;

    #[ORM\Column(length: 16, enumType: IncidentKind::class)]
    private IncidentKind $kind = IncidentKind::Outage;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function getSystem(): CustomerSystem { return $this->system; }
    public function setSystem(CustomerSystem $system): self
    {
        $this->system = $system;
        $this->setWorkspace($system->getWorkspace());
        return $this;
    }

    public function getKind(): IncidentKind { return $this->kind; }
    public function setKind(IncidentKind $kind): self { $this->kind = $kind; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(\DateTimeImmutable $t): self { $this->startedAt = $t; return $this; }

    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $t): self { $this->resolvedAt = $t; return $this; }

    public function isOpen(): bool { return $this->resolvedAt === null; }
}
