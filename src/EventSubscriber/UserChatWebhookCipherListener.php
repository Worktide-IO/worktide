<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Channels\SecretBox;
use App\Entity\UserChatWebhook;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Transparent at-rest encryption for {@see UserChatWebhook::$url} — an incoming-
 * webhook URL is a bearer-capability secret. Mirrors
 * {@see ChannelAuthConfigCipherListener} but for a single string field: seal
 * before the row hits the DB, open on hydration, so a DB dump never reveals the
 * URL and app code stays oblivious. Idempotent (already-sealed values pass
 * through; unseal returns the input when it isn't a valid blob).
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 0)]
#[AsDoctrineListener(event: Events::preUpdate, priority: 0)]
#[AsDoctrineListener(event: Events::postLoad, priority: 0)]
final class UserChatWebhookCipherListener
{
    public function __construct(
        private readonly SecretBox $secretBox,
    ) {}

    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if ($entity instanceof UserChatWebhook) {
            $entity->setUrl($this->secretBox->seal($entity->getUrl()));
        }
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof UserChatWebhook || !$event->hasChangedField('url')) {
            return;
        }
        $sealed = $this->secretBox->seal((string) $event->getNewValue('url'));
        $entity->setUrl($sealed);
        // Keep the UoW changeset consistent with the mutated value.
        $event->setNewValue('url', $sealed);
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();
        if ($entity instanceof UserChatWebhook) {
            $entity->setUrl($this->secretBox->open($entity->getUrl()) ?? $entity->getUrl());
        }
    }
}
