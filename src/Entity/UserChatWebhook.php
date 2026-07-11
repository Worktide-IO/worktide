<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ChatProvider;
use App\Entity\Trait\EntityIdTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\UserChatWebhookRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's personal chat notification destination — an incoming-webhook URL for
 * Slack / Mattermost / Teams. One per user (their own setting, not workspace-
 * scoped); notifications are pushed here when the user enables the chat channel
 * in their preferences.
 *
 * The `url` is a bearer-capability secret, so it's encrypted at rest by
 * {@see \App\EventSubscriber\UserChatWebhookCipherListener} (application code
 * reads/writes it as plaintext). Not an API Platform resource — managed through
 * the self-service {@see \App\Controller\Api\ChatWebhookController} /
 * {@see \App\Controller\Api\Portal\PortalChatWebhookController} so the URL is
 * never echoed back.
 */
#[ORM\Entity(repositoryClass: UserChatWebhookRepository::class)]
#[ORM\Table(name: 'user_chat_webhooks')]
#[ORM\UniqueConstraint(name: 'user_chat_webhook_user_uniq', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class UserChatWebhook
{
    use EntityIdTrait;
    use TimestampableTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 16, enumType: ChatProvider::class)]
    private ChatProvider $provider = ChatProvider::Slack;

    /** Incoming-webhook URL. Encrypted at rest; plaintext to app code. */
    #[ORM\Column(type: 'text')]
    private string $url = '';

    #[ORM\Column]
    private bool $enabled = true;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getProvider(): ChatProvider
    {
        return $this->provider;
    }

    public function setProvider(ChatProvider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}
