<?php

declare(strict_types=1);

namespace App\Service\I18n;

use App\Entity\Contact;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Resolves the active display locale for the current request.
 *
 * Precedence: authenticated User.preferredLanguage → the current workspace's
 * locale (via the X-Workspace-Id header) → the app default. Each step is only
 * honoured if the value is a supported locale.
 *
 * Deliberately does NOT read the Accept-Language header: locale is derived
 * from stored per-user/-workspace state, so a given (auth, workspace) pair
 * always renders the same representation — the planned reverse-proxy HTTP
 * cache (roadmap Phase S) keys on exactly those, so responses stay cacheable.
 *
 * The resolution is memoised per request. {@see reset()} clears it between
 * requests so a long-running (FrankenPHP/Messenger worker) container never
 * serves a stale locale.
 */
final class LocaleResolver implements ResetInterface
{
    private ?string $memo = null;

    /**
     * @param list<string> $supportedLocales
     */
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly array $supportedLocales,
        private readonly string $defaultLocale,
    ) {}

    public function resolve(): string
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        return $this->memo = $this->compute();
    }

    public function isSupported(string $locale): bool
    {
        return \in_array($locale, $this->supportedLocales, true);
    }

    /**
     * @return list<string>
     */
    public function supportedLocales(): array
    {
        return $this->supportedLocales;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function reset(): void
    {
        $this->memo = null;
    }

    private function compute(): string
    {
        // 1) User preference — no query, the user is already on the token.
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $pref = $user->getPreferredLanguage();
            if ($pref !== null && $this->isSupported($pref)) {
                return $pref;
            }
        }

        // 2) Current workspace default (only when the user has no preference).
        //    Staff carry the workspace on X-Workspace-Id; portal users don't send
        //    that header, so fall back to the workspace behind their contact so an
        //    existing German workspace's portal stays German until the customer
        //    picks a language.
        $workspaceLocale = $this->currentWorkspaceLocale() ?? $this->portalWorkspaceLocale($user);
        if ($workspaceLocale !== null && $this->isSupported($workspaceLocale)) {
            return $workspaceLocale;
        }

        // 3) App default.
        return $this->defaultLocale;
    }

    private function portalWorkspaceLocale(?object $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }

        $contact = $this->em->getRepository(Contact::class)->findOneBy(['linkedUser' => $user]);

        return $contact?->getCustomer()?->getWorkspace()?->getLocale();
    }

    private function currentWorkspaceLocale(): ?string
    {
        $requested = $this->requestStack->getCurrentRequest()?->headers->get('X-Workspace-Id');
        if ($requested === null || $requested === '') {
            return null;
        }
        try {
            $uuid = Uuid::fromString($requested);
        } catch (\InvalidArgumentException) {
            return null;
        }

        // find() hits the identity map / L2 cache first, so the co-membership
        // check already run by WorkspaceScopeExtension typically warms this.
        $workspace = $this->em->find(Workspace::class, $uuid);

        return $workspace?->getLocale();
    }
}
