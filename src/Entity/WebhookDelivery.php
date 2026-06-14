<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Enum\WebhookDeliveryStatus;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\WebhookDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Single attempt to deliver one DomainEvent to one Webhook. One log row per
 * (webhook, event, attempt) — retries get their own row so the timeline is
 * complete.
 *
 * Read-only via the API (operators inspect the log; retries happen on the
 * Messenger transport's own schedule, not via REST).
 */
#[ORM\Entity(repositoryClass: WebhookDeliveryRepository::class)]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\Index(name: 'webhook_delivery_webhook_idx', columns: ['webhook_id'])]
#[ORM\Index(name: 'webhook_delivery_status_idx', columns: ['status'])]
#[ORM\Index(name: 'webhook_delivery_event_idx', columns: ['event_id'])]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    shortName: 'WebhookDelivery',
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('VIEW', object.getWebhook().getWorkspace())"),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['webhook' => 'exact', 'status' => 'exact', 'eventName' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['attemptedAt', 'createdAt'])]
class WebhookDelivery
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Webhook $webhook;

    /** Snapshot of the source DomainEvent.aggregateId — reference only, no FK to domain_events. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $eventId = null;

    #[ORM\Column(length: 120)]
    private string $eventName;

    #[ORM\Column(length: 16, enumType: WebhookDeliveryStatus::class)]
    private WebhookDeliveryStatus $status = WebhookDeliveryStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?int $httpStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private int $attempt = 1;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $attemptedAt = null;

    public function getWebhook(): Webhook { return $this->webhook; }
    public function setWebhook(Webhook $w): self { $this->webhook = $w; return $this; }

    public function getEventId(): ?Uuid { return $this->eventId; }
    public function setEventId(?Uuid $id): self { $this->eventId = $id; return $this; }

    public function getEventName(): string { return $this->eventName; }
    public function setEventName(string $name): self { $this->eventName = $name; return $this; }

    public function getStatus(): WebhookDeliveryStatus { return $this->status; }
    public function setStatus(WebhookDeliveryStatus $s): self { $this->status = $s; return $this; }

    public function getHttpStatus(): ?int { return $this->httpStatus; }
    public function setHttpStatus(?int $code): self { $this->httpStatus = $code; return $this; }

    public function getResponseBody(): ?string { return $this->responseBody; }
    public function setResponseBody(?string $body): self
    {
        // Cap stored body to avoid bloating logs on misbehaving consumers.
        $this->responseBody = $body === null ? null : mb_substr($body, 0, 4096);
        return $this;
    }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $msg): self { $this->errorMessage = $msg; return $this; }

    public function getAttempt(): int { return $this->attempt; }
    public function setAttempt(int $n): self { $this->attempt = $n; return $this; }

    public function getDurationMs(): ?int { return $this->durationMs; }
    public function setDurationMs(?int $ms): self { $this->durationMs = $ms; return $this; }

    public function getAttemptedAt(): ?\DateTimeImmutable { return $this->attemptedAt; }
    public function setAttemptedAt(?\DateTimeImmutable $when): self { $this->attemptedAt = $when; return $this; }
}
