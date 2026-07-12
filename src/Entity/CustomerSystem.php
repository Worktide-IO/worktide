<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Entity\Enum\SystemEnvironment;
use App\Entity\Enum\SystemType;
use App\Entity\Trait\AuditableTrait;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\ExternalReferenceTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TaggableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Entity\Trait\VersionedTrait;
use App\Entity\Trait\WorkspaceScopedTrait;
use App\Repository\CustomerSystemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A concrete system instance the agency runs for a customer — one TYPO3
 * main site, one WordPress blog, one Shopware store. Customers may have
 * any number of systems and each system may carry its own
 * ServiceSubscriptions for hosting / maintenance / SLA.
 *
 * The agency cares about three buckets of data here:
 *   1. Operational: live + staging URLs, hosting provider, environment
 *      label, admin entry-point URL.
 *   2. Maintenance: SystemType drives what UI controls and what dashboards
 *      apply (a TYPO3-specific health-check page makes no sense on a
 *      WordPress instance).
 *   3. Notes: a free-form text field for credentials, quirks, contacts.
 *      INTENTIONALLY plaintext for now — secret encryption needs a proper
 *      KMS story (see roadmap CRM-3). Until then, treat this field as
 *      privileged like any other PII / credentials column.
 *
 * Customer ↔ Workspace consistency: `workspace` is denormalized from the
 * customer so voters and filters don't always have to join.
 */
#[ORM\Entity(repositoryClass: CustomerSystemRepository::class)]
#[ORM\Table(name: 'customer_systems')]
#[ORM\Index(name: 'customer_system_customer_idx', columns: ['customer_id'])]
#[ORM\Index(name: 'customer_system_workspace_idx', columns: ['workspace_id'])]
#[ORM\Index(name: 'customer_system_type_idx', columns: ['type'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'CustomerSystem',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWorkspace())"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Patch(security: "is_granted('EDIT', object.getWorkspace())"),
        new Delete(security: "is_granted('EDIT', object.getWorkspace())"),
    ],
    mercure: true,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'workspace' => 'exact',
    'customer' => 'exact',
    'type' => 'exact',
    'environment' => 'exact',
    'name' => 'partial',
    'hostingProvider' => 'partial',
    'tags.id' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'type', 'createdAt'])]
class CustomerSystem implements TaggableInterface
{
    use EntityIdTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;
    use WorkspaceScopedTrait;
    use VersionedTrait;
    use AuditableTrait;
    use ExternalReferenceTrait;
    use TaggableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(length: 24, enumType: SystemType::class)]
    private SystemType $type = SystemType::Other;

    /**
     * Optional version tag of the hosted system itself — major release is
     * usually enough ("13", "v6.6"). Named `systemVersion` not `version` to
     * avoid colliding with VersionedTrait's optimistic-locking column.
     */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $systemVersion = null;

    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\Url(requireTld: true)]
    private ?string $url = null;

    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\Url(requireTld: true)]
    private ?string $stagingUrl = null;

    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\Url(requireTld: true)]
    private ?string $adminLoginUrl = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $hostingProvider = null;

    #[ORM\Column(length: 24, enumType: SystemEnvironment::class)]
    private SystemEnvironment $environment = SystemEnvironment::Production;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $credentialsNotes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private bool $isActive = true;

    /** @var Collection<int, ServiceSubscription> */
    #[ORM\OneToMany(targetEntity: ServiceSubscription::class, mappedBy: 'system')]
    private Collection $subscriptions;

    public function __construct()
    {
        $this->subscriptions = new ArrayCollection();
        $this->tags = new ArrayCollection();
    }

    public function getCustomer(): Customer { return $this->customer; }
    public function setCustomer(Customer $c): self
    {
        $this->customer = $c;
        $this->setWorkspace($c->getWorkspace());
        return $this;
    }

    public function getName(): string { return $this->name; }
    public function setName(string $n): self { $this->name = $n; return $this; }

    public function getType(): SystemType { return $this->type; }
    public function setType(SystemType $t): self { $this->type = $t; return $this; }

    public function getSystemVersion(): ?string { return $this->systemVersion; }
    public function setSystemVersion(?string $v): self { $this->systemVersion = $v; return $this; }

    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): self { $this->url = $v; return $this; }

    public function getStagingUrl(): ?string { return $this->stagingUrl; }
    public function setStagingUrl(?string $v): self { $this->stagingUrl = $v; return $this; }

    public function getAdminLoginUrl(): ?string { return $this->adminLoginUrl; }
    public function setAdminLoginUrl(?string $v): self { $this->adminLoginUrl = $v; return $this; }

    public function getHostingProvider(): ?string { return $this->hostingProvider; }
    public function setHostingProvider(?string $v): self { $this->hostingProvider = $v; return $this; }

    public function getEnvironment(): SystemEnvironment { return $this->environment; }
    public function setEnvironment(SystemEnvironment $e): self { $this->environment = $e; return $this; }

    public function getCredentialsNotes(): ?string { return $this->credentialsNotes; }
    public function setCredentialsNotes(?string $v): self { $this->credentialsNotes = $v; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): self { $this->notes = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; return $this; }

    /** @return Collection<int, ServiceSubscription> */
    public function getSubscriptions(): Collection { return $this->subscriptions; }
}
