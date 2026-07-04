<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\SystemUptimeDayRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One day's uptime rollup for a monitored {@see CustomerSystem} — the raw
 * material for the portal Monitoring screen (uptime %, average latency, and the
 * 30-day sparkline). Written by the probe command (app:monitoring:probe), which
 * recomputes the day's figures from its accumulated samples.
 *
 * One row per (system, day). Aggregated over a window it yields the "current"
 * uptime %/latency; the current status itself comes from open incidents.
 */
#[ORM\Entity(repositoryClass: SystemUptimeDayRepository::class)]
#[ORM\Table(name: 'system_uptime_days')]
#[ORM\UniqueConstraint(name: 'system_uptime_day_system_day_uniq', columns: ['system_id', 'day'])]
#[ORM\Index(name: 'system_uptime_day_system_idx', columns: ['system_id'])]
#[ORM\HasLifecycleCallbacks]
class SystemUptimeDay
{
    use EntityIdTrait;
    use TimestampableTrait;
    use WorkspaceScopedTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CustomerSystem $system;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    /** Share of successful checks that day, 0–100. */
    #[ORM\Column(type: 'float')]
    private float $uptimePct = 100.0;

    /** Average response time in ms across the day's checks. */
    #[ORM\Column(nullable: true)]
    private ?int $avgResponseMs = null;

    #[ORM\Column]
    private int $sampleCount = 0;

    public function getSystem(): CustomerSystem { return $this->system; }
    public function setSystem(CustomerSystem $system): self
    {
        $this->system = $system;
        $this->setWorkspace($system->getWorkspace());
        return $this;
    }

    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function setDay(\DateTimeImmutable $day): self { $this->day = $day; return $this; }

    public function getUptimePct(): float { return $this->uptimePct; }
    public function setUptimePct(float $pct): self { $this->uptimePct = max(0.0, min(100.0, $pct)); return $this; }

    public function getAvgResponseMs(): ?int { return $this->avgResponseMs; }
    public function setAvgResponseMs(?int $ms): self { $this->avgResponseMs = $ms; return $this; }

    public function getSampleCount(): int { return $this->sampleCount; }
    public function setSampleCount(int $n): self { $this->sampleCount = $n; return $this; }
}
