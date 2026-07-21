<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Channels\SecretBox;
use App\Entity\CustomerBookmark;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Transparently encrypts/decrypts {@see CustomerBookmark}::$credentials
 * in the database — same pattern as {@see ChannelAuthConfigCipherListener}
 * but keyed with a separate domain prefix so rotating channel secrets never
 * destroys bookmark credentials.
 *
 * Each leaf value in `credentials` is individually wrapped as
 * `{"enc": "<base64-nonce+ciphertext>"}` so we can decrypt per-field without
 * knowing the order of keys.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postLoad)]
final class BookmarkCredentialsCipherListener
{
    private SecretBox $box;

    public function __construct(string $appSecret)
    {
        $this->box = new SecretBox($appSecret, 'worktide.bookmarks.v1');
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof CustomerBookmark) {
            return;
        }
        $entity->setCredentials($this->encryptLeafValues($entity->getCredentials()));
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof CustomerBookmark) {
            return;
        }
        $new = $entity->getCredentials();
        $oldEncrypted = $event->getOldValue('credentials') ?? [];

        // Merge-patch safety: carry over keys from the old value that were
        // already encrypted but not re-sent by the SPA (the SPA never sends
        // credentials back, so we must restore them from the encrypted store).
        foreach ($oldEncrypted as $key => $enc) {
            if (\is_string($enc) && !\array_key_exists($key, $new)) {
                $new[$key] = $enc;
            }
        }

        $entity->setCredentials($this->encryptLeafValues($new));
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof CustomerBookmark) {
            return;
        }
        $entity->setCredentials($this->decryptLeafValues($entity->getCredentials()));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function encryptLeafValues(array $values): array
    {
        $out = [];
        foreach ($values as $key => $raw) {
            if (\is_string($raw) && \str_starts_with($raw, 'enc:')) {
                $out[$key] = $raw;
                continue; // already encrypted
            }
            $out[$key] = \is_string($raw) && $raw !== '' ? 'enc:' . $this->box->seal($raw) : '';
        }
        return $out;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private function decryptLeafValues(array $values): array
    {
        $out = [];
        foreach ($values as $key => $raw) {
            if (\is_string($raw) && \str_starts_with($raw, 'enc:')) {
                $dec = $this->box->open(\substr($raw, 4)) ?? '';
                $out[$key] = $dec;
            } else {
                $out[$key] = \is_string($raw) ? $raw : '';
            }
        }
        return $out;
    }
}
