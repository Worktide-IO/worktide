<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Contact;
use App\Entity\User;
use App\Repository\ContactRepository;
use App\Repository\UserRepository;
use App\Service\PasswordResetService;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * STAFF-side actions to grant/revoke portal access for a CRM {@see Contact}.
 *
 * These live under the `^/v1` (ROLE_USER) firewall — they are called by staff
 * from the CRM, not by portal users. Authorization is a workspace `EDIT` check
 * on the contact's customer.
 *
 * Grant provisions a dedicated {@see User} (ROLE_PORTAL, no ROLE_USER, not a
 * WorkspaceMember), links it via {@see Contact::$linkedUser}, and mails a
 * "set your password" link (mirrors {@see \App\Controller\Api\ForgotPasswordController}
 * + {@see \App\Controller\Api\WorkspaceInvitationAcceptController}).
 */
final class PortalAccessGrantController
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PasswordResetService $resets,
        private readonly Security $security,
    ) {}

    #[Route(
        path: '/v1/contacts/{id}/grant-portal-access',
        name: 'api_contacts_grant_portal_access',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function grant(string $id): JsonResponse
    {
        $contact = $this->loadEditableContact($id);

        $email = $contact->getEmail();
        if ($email === null || $email === '') {
            throw new BadRequestHttpException('Contact has no email — cannot grant portal access.');
        }

        // Idempotent: an already-linked contact just reports its state. The
        // invitation email is NO LONGER sent here — provisioning ("Freischaltung")
        // is separate from inviting, so the staff UI can *offer* to send it via
        // POST .../send-portal-invitation.
        $user = $contact->getLinkedUser();
        if ($user instanceof User) {
            return new JsonResponse($this->grantResult($contact, $user));
        }

        // Don't hijack an existing (possibly staff) account.
        if ($this->users->findOneBy(['email' => $email]) !== null) {
            throw new ConflictHttpException('An account with this email already exists.');
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($contact->getFirstName())
            ->setLastName($contact->getLastName())
            ->setRoles(['ROLE_PORTAL']);
        // Unusable random password until the contact sets their own via the link.
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(24))));
        $this->em->persist($user);
        $this->em->flush(); // so getId() is populated

        $contact->setLinkedUser($user);
        $this->em->flush();

        return new JsonResponse($this->grantResult($contact, $user), 201);
    }

    /**
     * Send (or re-send) the branded portal invitation — the set-password link
     * plus the workspace's configured welcome text — to an already-provisioned
     * portal contact. This is the "offer to invite" that follows Freischaltung.
     */
    #[Route(
        path: '/v1/contacts/{id}/send-portal-invitation',
        name: 'api_contacts_send_portal_invitation',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function sendInvitation(string $id): JsonResponse
    {
        $contact = $this->loadEditableContact($id);

        $user = $contact->getLinkedUser();
        if (!$user instanceof User) {
            throw new ConflictHttpException('Portal access is not granted yet — grant it first.');
        }

        $welcomeText = PortalAccessResolver::welcomeText($contact->getCustomer()->getWorkspace());
        $this->resets->sendPortalSetPasswordLink($user, $welcomeText);

        $contact->markPortalInvited();
        $this->em->flush();

        return new JsonResponse($this->grantResult($contact, $user, invited: true));
    }

    #[Route(
        path: '/v1/contacts/{id}/revoke-portal-access',
        name: 'api_contacts_revoke_portal_access',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function revoke(string $id): JsonResponse
    {
        $contact = $this->loadEditableContact($id);

        $user = $contact->getLinkedUser();
        if (!$user instanceof User) {
            // Idempotent: nothing linked.
            return new JsonResponse(['contactId' => $contact->getId()?->toRfc4122(), 'revoked' => true]);
        }

        // Break the identity chain (the PortalAccessResolver now denies this
        // account even if a JWT is still live) and neuter the login. Reset the
        // invite state so a later re-grant offers to send a fresh invitation.
        $contact->setLinkedUser(null);
        $contact->clearPortalInvited();
        $user->setRoles([]);
        $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(24))));
        $this->em->flush();

        return new JsonResponse(['contactId' => $contact->getId()?->toRfc4122(), 'revoked' => true]);
    }

    private function loadEditableContact(string $id): Contact
    {
        $contact = $this->contacts->find(Uuid::fromString($id));
        if (!$contact instanceof Contact) {
            throw new NotFoundHttpException('Contact not found.');
        }
        $workspace = $contact->getCustomer()->getWorkspace();
        if (!$this->security->isGranted('EDIT', $workspace)) {
            throw new AccessDeniedHttpException('Not allowed to manage this contact.');
        }
        if (!PortalAccessResolver::isPortalEnabled($workspace)) {
            throw new ConflictHttpException('Portal is not enabled for this workspace.');
        }
        return $contact;
    }

    /**
     * @return array<string, mixed>
     */
    private function grantResult(Contact $contact, User $user, bool $invited = false): array
    {
        return [
            'contactId' => $contact->getId()?->toRfc4122(),
            'userId' => $user->getId()?->toRfc4122(),
            'email' => $user->getEmail(),
            // Whether an invitation email was dispatched by *this* call, and the
            // overall invite state so the UI can show "invited on …" vs "offer".
            'invited' => $invited,
            'portalInvitedAt' => $contact->getPortalInvitedAt()?->format(\DATE_ATOM),
        ];
    }
}
