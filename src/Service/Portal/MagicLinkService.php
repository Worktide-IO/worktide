<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\Contact;
use App\Entity\DomainEventLog;
use App\Entity\MagicLoginToken;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Repository\MagicLoginTokenRepository;
use App\Security\PortalUserChecker;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Passwordless portal login via a single-use magic link.
 *
 * Today's sole caller is **staff impersonation**: a staff member issues a link
 * to preview the portal AS a portal contact (see
 * {@see \App\Controller\Api\Portal\PortalAccessGrantController} for the guarded
 * generation endpoint, {@see \App\Controller\Api\PortalMagicLinkController} for
 * the public consume). Token machinery mirrors {@see \App\Service\PasswordResetService}
 * (hashed at rest, single-use, one live token per target); the consume mints a
 * JWT like {@see \App\Controller\Api\WorkspaceInvitationAcceptController} but
 * deliberately issues **no refresh cookie** — the preview session is ephemeral
 * (dies with the 1h JWT / on reload), leaving no lingering credential.
 */
final class MagicLinkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MagicLoginTokenRepository $tokens,
        private readonly ContactRepository $contacts,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly PortalUserChecker $portalUserChecker,
        private readonly RequestStack $requestStack,
        private readonly string $portalBaseUrl,
    ) {}

    /**
     * Issue a one-time impersonation link for a portal-enabled contact and
     * return the portal URL. The caller (staff endpoint) has already verified
     * EDIT rights + that the contact has portal access. Records who impersonates
     * whom for audit; does NOT send any email — the URL is handed back to the
     * staff UI to open.
     */
    public function issueForImpersonation(Contact $contact, User $staff): string
    {
        $target = $contact->getLinkedUser();
        if (!$target instanceof User) {
            throw new \LogicException('Contact has no linked portal user.');
        }

        // Only ever one live magic-login token per target account.
        $this->tokens->deleteForUser($target);

        $plaintext = bin2hex(random_bytes(32));
        $token = (new MagicLoginToken())
            ->setUser($target)
            ->setIssuedByUser($staff)
            ->setTokenHash(hash('sha256', $plaintext));
        $this->em->persist($token);

        $this->em->persist(new DomainEventLog(
            name: 'portal.impersonation.issued',
            aggregateType: 'Portal',
            aggregateId: $target->getId(),
            workspace: $contact->getCustomer()->getWorkspace(),
            actor: $staff,
            payload: [
                'contactId' => $contact->getId()?->toRfc4122(),
                'targetUserId' => $target->getId()?->toRfc4122(),
                'targetEmail' => $target->getEmail(),
            ],
        ));
        $this->em->flush();

        return rtrim($this->portalBaseUrl, '/') . '/auth/magic?token=' . $plaintext;
    }

    /**
     * Consume a magic-login token and mint a portal session (JWT only, no
     * refresh cookie).
     *
     * @return array{token: string, impersonation: bool, customerName: ?string, issuedBy: ?string}|null
     *         null when the token is unknown/expired/used; throws
     *         CustomUserMessageAccountStatusException (via PortalUserChecker)
     *         when the target's portal access has since been switched off.
     */
    public function consume(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $entity = $this->tokens->findValidByHash(hash('sha256', $token), new \DateTimeImmutable());
        if (!$entity instanceof MagicLoginToken) {
            return null;
        }

        $user = $entity->getUser();
        // Re-run the live portal gate (contact active + customer portalEnabled):
        // the token was issued directly, so it bypasses the firewall's
        // user_checker for this one mint — throws if access was revoked meanwhile.
        $this->portalUserChecker->checkPostAuth($user);

        $entity->markUsed();

        $req = $this->requestStack->getMainRequest();
        $this->em->persist(new DomainEventLog(
            name: 'portal.impersonation.consumed',
            aggregateType: 'Portal',
            aggregateId: $user->getId(),
            workspace: null,
            actor: $user,
            payload: [
                'issuedByUserId' => $entity->getIssuedByUser()?->getId()?->toRfc4122(),
                'ip' => $req?->getClientIp() ?? 'unknown',
            ],
        ));
        $this->em->flush();

        $contact = $this->contacts->findOneBy(['linkedUser' => $user]);

        return [
            'token' => $this->jwt->create($user),
            'impersonation' => true,
            'customerName' => $contact?->getCustomer()->getName(),
            'issuedBy' => $entity->getIssuedByUser()?->getEmail(),
        ];
    }
}
