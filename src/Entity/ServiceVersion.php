<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Enum\BillingCycle;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\ServiceVersionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One priced fassung of a {@see Service}: net price + currency + billing cycle,
 * carrying its own version number. A version bundles "what does this service
 * cost and how often is it billed" — so offering the same service monthly and
 * yearly means two versions, and a price change is a new version (the old one
 * stays for the assignments already on it).
 *
 * Created through the service's release endpoint (POST /v1/services/{id}/versions)
 * so {@see self::$isCurrent} and {@see Service::$currentVersion} stay consistent
 * (see {@see \App\Service\Catalog\ServiceCatalogService}); label/changelog may be
 * edited afterwards. Price is immutable by convention — change it via a new
 * release, not a PATCH, to keep the history trustworthy.
 */
#[ORM\Entity(repositoryClass: ServiceVersionRepository::class)]
#[ORM\Table(name: 'service_versions')]
#[ORM\UniqueConstraint(name: 'service_version_uniq', columns: ['service_id', 'version_no'])]
#[ORM\Index(name: 'service_version_service_idx', columns: ['service_id'])]
#[ORM\Index(name: 'service_version_workspace_idx', columns: ['workspace_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'ServiceVersion',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        // Creation goes through POST /v1/services/{id}/versions so current-version
        // bookkeeping stays consistent; label/changelog are editable here.
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'service' => 'exact',
    'billingCycle' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['versionNo', 'effectiveFrom', 'createdAt'])]
class ServiceVersion
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use AuditableTrait;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Service $service;

    /** Sequential per service (1, 2, 3 …), assigned by ServiceCatalogService. */
    #[ORM\Column(options: ['default' => 1])]
    private int $versionNo = 1;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $changelog = null;

    /** Net price for ONE billing cycle, in the smallest currency unit (cents). */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $netPriceCents = 0;

    /** ISO 4217 alpha-3 in lowercase ("eur", "chf", "usd"). */
    #[ORM\Column(length: 3)]
    #[Assert\Length(min: 3, max: 3)]
    private string $currency = 'eur';

    #[ORM\Column(length: 16, enumType: BillingCycle::class)]
    private BillingCycle $billingCycle = BillingCycle::Monthly;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $effectiveFrom = null;

    /** Exactly one current version per service — maintained by ServiceCatalogService. */
    #[ApiProperty(writable: false)]
    #[ORM\Column(options: ['default' => false])]
    private bool $isCurrent = false;

    public function getService(): Service { return $this->service; }
    public function setService(Service $s): self
    {
        $this->service = $s;
        $this->setWorkspace($s->getWorkspace());
        return $this;
    }

    public function getVersionNo(): int { return $this->versionNo; }
    public function setVersionNo(int $n): self { $this->versionNo = $n; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $l): self { $this->label = $l; return $this; }

    public function getChangelog(): ?string { return $this->changelog; }
    public function setChangelog(?string $c): self { $this->changelog = $c; return $this; }

    public function getNetPriceCents(): int { return $this->netPriceCents; }
    public function setNetPriceCents(int $c): self { $this->netPriceCents = $c; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): self { $this->currency = strtolower(trim($c)); return $this; }

    public function getBillingCycle(): BillingCycle { return $this->billingCycle; }
    public function setBillingCycle(BillingCycle $c): self { $this->billingCycle = $c; return $this; }

    public function getEffectiveFrom(): ?\DateTimeImmutable { return $this->effectiveFrom; }
    public function setEffectiveFrom(?\DateTimeImmutable $d): self { $this->effectiveFrom = $d; return $this; }

    public function isCurrent(): bool { return $this->isCurrent; }
    public function setIsCurrent(bool $v): self { $this->isCurrent = $v; return $this; }

    /**
     * Annualised net price in cents — Yearly * 1, HalfYearly * 2, Monthly * 12,
     * Once * 1. Convenient for revenue dashboards (MRR / ARR).
     */
    public function annualPriceCents(): int
    {
        return $this->netPriceCents * $this->billingCycle->annualMultiplier();
    }
}
