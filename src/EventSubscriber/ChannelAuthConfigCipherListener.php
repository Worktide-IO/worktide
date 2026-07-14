<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Channels\SecretBox;
use App\Entity\Channel;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Transparent at-rest encryption for {@see Channel::$authConfig}.
 *
 * The application code reads/writes `authConfig` as a plain
 * associative array — passwords, OAuth refresh-tokens, API keys.
 * This listener encrypts every leaf value with libsodium before
 * the row hits the DB and decrypts on hydration, so:
 *
 *  - A DB dump never reveals the secrets in cleartext.
 *  - Application code stays oblivious to the crypto.
 *  - APP_SECRET is the symmetric key (BLAKE2b-derived).
 *
 * Each leaf value is wrapped as `["enc": "<base64-blob>"]` so we
 * can tell encrypted-vs-plain at a glance and survive a single
 * leaf being rotated independently.
 *
 * Idempotent on both sides — already-encrypted leaves on persist
 * stay encrypted; non-encrypted leaves on load pass through
 * untouched (smooths the migration when older rows exist).
 */
#[AsDoctrineListener(event: Events::prePersist, priority: 0)]
#[AsDoctrineListener(event: Events::preUpdate, priority: 0)]
#[AsDoctrineListener(event: Events::postLoad, priority: 0)]
final class ChannelAuthConfigCipherListener
{
    public function __construct(
        private readonly SecretBox $secretBox,
    ) {}

    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof Channel) {
            return;
        }
        $entity->setAuthConfig($this->encryptTree($entity->getAuthConfig()));
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof Channel) {
            return;
        }
        if (!$event->hasChangedField('authConfig')) {
            return;
        }
        $new = $event->getNewValue('authConfig');
        if (!is_array($new)) {
            return;
        }
        // Preserve secrets the client omitted. A merge-patch update replaces the
        // whole authConfig object, but write-only secrets (passwords, API keys,
        // tokens) are never sent back to the browser, so the client can't resend
        // them — the SourceWizard leaves the password field blank ("leer =
        // unverändert"). Carry over any key present in the stored (old) value but
        // ABSENT from the incoming one, otherwise editing any unrelated field
        // silently wipes the password → IMAP/Jira/Redmine auth starts failing.
        // The old value is already encrypted; encryptTree leaves it untouched.
        // An explicit null still clears a key (omission != null).
        $old = $event->getOldValue('authConfig');
        if (is_array($old)) {
            foreach ($old as $k => $v) {
                if (!array_key_exists($k, $new)) {
                    $new[$k] = $v;
                }
            }
        }
        $encrypted = $this->encryptTree($new);
        // The DB row holds the encrypted form…
        $event->setNewValue('authConfig', $encrypted);
        // …but keep the in-memory copy DECRYPTED. postLoad decrypts on hydration
        // yet Doctrine's original snapshot still holds the encrypted value, so
        // authConfig looks "changed" on every unrelated flush (cursor, lastSync,
        // …) and this listener runs. Re-encrypting the entity in memory would
        // then leave adapters reading `{enc: …}` arrays and casting them to
        // string ("Array to string conversion"). Decrypting keeps app code —
        // e.g. RedmineAdapter/EmailImapAdapter — seeing plain string secrets.
        $entity->setAuthConfig($this->decryptTree($encrypted));
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof Channel) {
            return;
        }
        $decrypted = $this->decryptTree($entity->getAuthConfig());
        $entity->setAuthConfig($decrypted);

        // Realign Doctrine's change-tracking snapshot to the decrypted value.
        // Otherwise the snapshot keeps the encrypted form, so authConfig reads as
        // "changed" on EVERY flush that has this Channel loaded — Doctrine then
        // spuriously UPDATEs the row (re-encrypting authConfig) and bumps its
        // optimistic-lock version. That turns any flush touching a Channel (e.g.
        // the inbound worker resolving event->getChannel()) into a second writer,
        // which makes a concurrent `channel:pull` lose the version race on its
        // cursor write. With the snapshot aligned, an unrelated flush leaves
        // authConfig out of the changeset entirely.
        $om = $event->getObjectManager();
        if ($om instanceof EntityManagerInterface) {
            $om->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($entity), 'authConfig', $decrypted);
        }
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function encryptTree(array $tree): array
    {
        $out = [];
        foreach ($tree as $k => $v) {
            if (is_array($v) && !$this->isEncryptedWrapper($v)) {
                // Nested structures are encrypted as JSON blobs so we
                // don't try to interpret adapter-specific shapes.
                $out[$k] = ['enc' => $this->secretBox->seal(json_encode($v, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))];
                continue;
            }
            if ($this->isEncryptedWrapper($v)) {
                // Already encrypted — leave as-is (idempotent).
                $out[$k] = $v;
                continue;
            }
            if ($v === null) {
                $out[$k] = null;
                continue;
            }
            $out[$k] = ['enc' => $this->secretBox->seal((string) $v)];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function decryptTree(array $tree): array
    {
        $out = [];
        foreach ($tree as $k => $v) {
            if (!$this->isEncryptedWrapper($v)) {
                $out[$k] = $v;
                continue;
            }
            $plain = $this->secretBox->open($v['enc']);
            if ($plain === null) {
                // Tamper / corruption — keep the row loadable but flag
                // the missing secret so callers can surface a useful
                // error rather than crash with a fatal.
                $out[$k] = null;
                continue;
            }
            // Try JSON-decode first (nested structures); fall back to
            // string for the typical password / token leaf.
            $decoded = json_decode($plain, true);
            $out[$k] = json_last_error() === \JSON_ERROR_NONE && is_array($decoded)
                ? $decoded
                : $plain;
        }
        return $out;
    }

    private function isEncryptedWrapper(mixed $v): bool
    {
        return is_array($v) && \count($v) === 1 && isset($v['enc']) && is_string($v['enc']);
    }
}
