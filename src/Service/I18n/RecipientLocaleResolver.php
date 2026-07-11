<?php

declare(strict_types=1);

namespace App\Service\I18n;

use App\Entity\Contact;
use App\Entity\User;
use App\Entity\Workspace;

/**
 * Resolves the language to use for content sent TO a recipient — mail bodies,
 * notification titles — which are built OUTSIDE the recipient's own request (a
 * worker, or the acting user's request). The request-scoped {@see LocaleResolver}
 * can't help there, so this resolves from the recipient's stored state.
 *
 * User:    preferredLanguage → optional workspace locale → app default.
 * Contact: contact.locale → the customer's workspace locale → app default.
 */
final class RecipientLocaleResolver
{
    /**
     * @param list<string> $supportedLocales
     */
    public function __construct(
        private readonly array $supportedLocales,
        private readonly string $defaultLocale,
    ) {}

    public function forUser(User $user, ?Workspace $workspace = null): string
    {
        $pref = $user->getPreferredLanguage();
        if ($pref !== null && $this->isSupported($pref)) {
            return $pref;
        }

        return $this->workspaceOrDefault($workspace);
    }

    public function forContact(Contact $contact): string
    {
        $locale = $contact->getLocale();
        if ($locale !== null && $this->isSupported($locale)) {
            return $locale;
        }

        return $this->workspaceOrDefault($contact->getCustomer()->getWorkspace());
    }

    private function workspaceOrDefault(?Workspace $workspace): string
    {
        $wsLocale = $workspace?->getLocale();
        if ($wsLocale !== null && $this->isSupported($wsLocale)) {
            return $wsLocale;
        }

        return $this->defaultLocale;
    }

    private function isSupported(string $locale): bool
    {
        return \in_array($locale, $this->supportedLocales, true);
    }
}
