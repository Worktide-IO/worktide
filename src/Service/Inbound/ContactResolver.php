<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\Contact;
use App\Entity\Conversation;
use App\Entity\InboundEvent;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\ContactRepository;

/**
 * Auto-resolve: matches an inbound message's sender email to a known
 * {@see Contact} in the workspace and enriches the event + its conversation
 * with the customer context (Phase C Schicht 4). Called from
 * {@see InboundEventProcessor}.
 *
 * Resolution is lookup-only — an unknown sender is left unresolved rather than
 * spawning a junk Contact/Customer. When a match is found:
 *   - the event's `senderContact` is set (if not already), and
 *   - the conversation's `customer` is set (if the thread had none).
 */
final class ContactResolver
{
    /**
     * Local-parts that mark an automated / shared sender rather than a real
     * person. A contact-form mailer, bounce daemon or no-reply address must
     * never match a Contact — otherwise every submission from a shared form
     * address (e.g. `info@…` sending on behalf of the actual person, whose
     * real address sits in the body) gets mis-linked to whichever Contact
     * happens to carry that address. Matched case-insensitively, exact
     * local-part.
     */
    private const IGNORED_LOCAL_PARTS = [
        'no-reply', 'noreply', 'donotreply', 'do-not-reply', 'no_reply',
        'mailer-daemon', 'postmaster', 'bounce', 'bounces', 'notifications',
        'notification', 'mailer', 'daemon',
    ];

    /** @var array<string, true> lowercased full addresses that must never match (env override) */
    private readonly array $ignoredEmails;

    /**
     * Per-workspace cache of the workspace's own channel addresses, so a
     * backfill run over many conversations queries the channels once per
     * workspace rather than once per conversation. Keyed by workspace object id.
     *
     * @var array<int, array<string, true>>
     */
    private array $ownAddressCache = [];

    /**
     * @param string $ignoredSenderEmails comma-separated list of full addresses
     *   that must never resolve to a Contact — an escape hatch for aliases /
     *   forwarders not modelled as a Channel. The workspace's own Channel
     *   addresses are denied automatically (see {@see isIgnoredSender()}).
     *   See INBOUND_IGNORED_SENDER_EMAILS.
     */
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly ChannelRepository $channels,
        ?string $ignoredSenderEmails = '',
    ) {
        $ignored = [];
        foreach (explode(',', (string) $ignoredSenderEmails) as $raw) {
            $email = mb_strtolower(trim($raw));
            if ($email !== '') {
                $ignored[$email] = true;
            }
        }
        $this->ignoredEmails = $ignored;
    }

    public function resolveForEvent(InboundEvent $event): ?Contact
    {
        $email = $this->extractEmail((string) $event->getSenderRaw());
        if ($email === null || $this->isIgnoredSender($email, $event->getWorkspace())) {
            return null;
        }

        $contact = $this->contacts->findOneByWorkspaceAndEmail($event->getWorkspace(), $email);
        if ($contact === null) {
            return null;
        }

        if ($event->getSenderContact() === null) {
            $event->setSenderContact($contact);
        }

        $conversation = $event->getConversation();
        if ($conversation !== null && $conversation->getCustomer() === null) {
            $conversation->setCustomer($contact->getCustomer());
        }

        return $contact;
    }

    /**
     * Same lookup for an existing {@see Conversation} (backfill / re-resolve):
     * match its stored sender against a known Contact and adopt that Contact's
     * Customer when the thread has none. Lookup-only, idempotent — a thread that
     * already has a customer, has no sender, or whose sender is unknown is left
     * untouched. Shares the matching rules with {@see resolveForEvent()}.
     */
    public function resolveForConversation(Conversation $conversation): ?Contact
    {
        if ($conversation->getCustomer() !== null) {
            return null;
        }

        $email = $this->extractEmail((string) $conversation->getSenderRaw());
        if ($email === null || $this->isIgnoredSender($email, $conversation->getWorkspace())) {
            return null;
        }

        $contact = $this->contacts->findOneByWorkspaceAndEmail($conversation->getWorkspace(), $email);
        if ($contact === null) {
            return null;
        }

        $conversation->setCustomer($contact->getCustomer());

        return $contact;
    }

    /**
     * Pull a bare, validated, lowercased email out of a raw `Name <email>` /
     * `email` from-header. Deny-listing (see {@see isIgnoredSender()}) is applied
     * by the callers once they have the email — so an empty/malformed sender
     * short-circuits before the workspace is ever touched.
     */
    private function extractEmail(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
            $raw = $m[1];
        }
        $raw = trim($raw);

        return filter_var($raw, \FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($raw) : null;
    }

    /**
     * True for a sender that must never resolve to a Contact:
     *   - one of the workspace's own Channel addresses (self / loop-back mail),
     *   - an env-configured shared address / alias, or
     *   - an automated-role local-part (no-reply, mailer-daemon, …).
     */
    private function isIgnoredSender(string $email, ?Workspace $workspace): bool
    {
        if (isset($this->ignoredEmails[$email])) {
            return true;
        }
        $localPart = substr($email, 0, (int) strpos($email, '@'));
        if (\in_array($localPart, self::IGNORED_LOCAL_PARTS, true)) {
            return true;
        }

        return $workspace !== null && isset($this->ownAddresses($workspace)[$email]);
    }

    /**
     * The workspace's own Channel addresses as a lowercased lookup set, loaded
     * once per workspace (backfill iterates many conversations of one tenant).
     *
     * @return array<string, true>
     */
    private function ownAddresses(Workspace $workspace): array
    {
        $key = spl_object_id($workspace);
        if (!isset($this->ownAddressCache[$key])) {
            $set = [];
            foreach ($this->channels->findOwnAddresses($workspace) as $address) {
                $set[$address] = true;
            }
            $this->ownAddressCache[$key] = $set;
        }

        return $this->ownAddressCache[$key];
    }
}
