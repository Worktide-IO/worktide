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
        host: 'api.worktide.ddev.site',
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

        // Idempotent: a contact already linked just gets a fresh set-password mail.
        $user = $contact->getLinkedUser();
        if ($user instanceof User) {
            $this->resets->sendPortalSetPasswordLink($user);

            return new JsonResponse($this->grantResult($contact, $user, resent: true));
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

        $this->resets->sendPortalSetPasswordLink($user);

        return new JsonResponse($this->grantResult($contact, $user, resent: false), 201);
    }

    #[Route(
        path: '/v1/contacts/{id}/revoke-portal-access',
        name: 'api_contacts_revoke_portal_access',
        host: 'api.worktide.ddev.site',
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
        // account even if a JWT is still live) and neuter the login.
        $contact->setLinkedUser(null);
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
    private function grantResult(Contact $contact, User $user, bool $resent): array
    {
        return [
            'contactId' => $contact->getId()?->toRfc4122(),
            'userId' => $user->getId()?->toRfc4122(),
            'email' => $user->getEmail(),
            'setPasswordMailResent' => $resent,
        ];
    }
}
