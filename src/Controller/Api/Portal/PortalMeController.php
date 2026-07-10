<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\User;
use App\Service\I18n\LocaleResolver;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * /v1/portal/me — the portal bootstrap call (GET) + self-service settings (PATCH).
 *
 * GET returns a curated view of who the portal user is (their Contact + Customer),
 * the workspace display name, the per-workspace feature flags that drive the
 * portal navigation, and the user's display-language settings. Never exposes
 * staff/internal fields.
 *
 * PATCH lets a portal user set their own preferred display language. Portal
 * users are ROLE_PORTAL (locked out of the staff `/v1/me/profile`), so they
 * need their own write path; the field itself lives on the shared User entity.
 *
 * The `features` map spans all planned portal screens so the frontend can
 * render the full navigation with not-yet-enabled items locked.
 */
final class PortalMeController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly LocaleResolver $localeResolver,
    ) {}

    #[Route(
        path: '/v1/portal/me',
        name: 'api_portal_me',
        methods: ['GET'],
    )]
    public function get(): JsonResponse
    {
        $this->portal->assertPortalEnabled();

        return new JsonResponse($this->snapshot());
    }

    #[Route(
        path: '/v1/portal/me',
        name: 'api_portal_me_patch',
        methods: ['PATCH'],
    )]
    public function patch(Request $request): JsonResponse
    {
        $this->portal->assertPortalEnabled();
        $user = $this->requireUser();
        $body = $this->body($request);

        // Allowlist — only the preferred language is self-service here.
        if (array_key_exists('preferredLanguage', $body)) {
            $value = $body['preferredLanguage'];
            if ($value === null || $value === '') {
                $user->setPreferredLanguage(null);
            } elseif (is_string($value) && $this->localeResolver->isSupported($value)) {
                $user->setPreferredLanguage($value);
            } else {
                throw new BadRequestHttpException(sprintf(
                    'preferredLanguage must be null or one of: %s.',
                    implode(', ', $this->localeResolver->supportedLocales()),
                ));
            }
            $this->em->flush();
        }

        return new JsonResponse($this->snapshot());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $contact = $this->portal->contact();
        $customer = $this->portal->customer();
        $workspace = $this->portal->workspace();

        return [
            'contact' => [
                'id' => $contact->getId()?->toRfc4122(),
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'email' => $contact->getEmail(),
            ],
            'customer' => [
                'id' => $customer->getId()?->toRfc4122(),
                'name' => $customer->getName(),
            ],
            // The customer's visible (external, non-archived) projects — drives the
            // project picker when a customer files a ticket across several projects.
            'projects' => array_values(array_map(
                static fn ($project) => [
                    'id' => $project->getId()?->toRfc4122(),
                    'name' => $project->getName(),
                ],
                $this->portal->allowedProjects(),
            )),
            'workspaceName' => $workspace->getName(),
            'features' => $this->portal->features(),
            'preferredLanguage' => $this->requireUser()->getPreferredLanguage(),
            'supportedLanguages' => $this->localeResolver->supportedLocales(),
        ];
    }

    private function requireUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Not authenticated.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Body must be JSON.');
        }

        return is_array($decoded) ? $decoded : [];
    }
}
