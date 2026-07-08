<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Contact;
use App\Entity\Project;

/**
 * Resolves a block's `prefillFrom` source key to an authoritative value from
 * the portal context. Only a fixed whitelist is honoured — a form author can
 * pre-fill a hidden field from the signed-in contact or the target project, but
 * never from arbitrary request data, and the client can never override these on
 * submit (the submission service takes prefill values from here, not the body).
 */
final class FormPrefillResolver
{
    /** Whitelisted `prefillFrom` source keys. */
    public const SOURCES = ['contact.id', 'contact.email', 'contact.name', 'project.id'];

    /**
     * Build the key => value prefill map for a form's hidden/prefill blocks.
     *
     * @param array{pages: list<array<string, mixed>>, ...} $doc normalised document
     *
     * @return array<string, mixed> answer key => resolved value
     */
    public function resolve(array $doc, ?Contact $contact, ?Project $project): array
    {
        $out = [];
        foreach ($doc['pages'] as $page) {
            foreach ($page['blocks'] as $block) {
                $source = $block['prefillFrom'] ?? null;
                $key = (string) ($block['key'] ?? '');
                if ($source === null || $key === '') {
                    continue;
                }
                $value = $this->valueFor((string) $source, $contact, $project);
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }

    private function valueFor(string $source, ?Contact $contact, ?Project $project): ?string
    {
        return match ($source) {
            'contact.id' => $contact?->getId()?->toRfc4122(),
            'contact.email' => $contact?->getEmail(),
            'contact.name' => $contact !== null ? $contact->getFullName() : null,
            'project.id' => $project?->getId()?->toRfc4122(),
            default => null,
        };
    }
}
