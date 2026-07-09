<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Contact;
use App\Entity\User;
use App\Repository\ContactRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Gates portal logins on the per-customer "Freischaltung" ({@see Customer::$portalEnabled}).
 *
 * Wired as the `user_checker` on the `api_login`, `api_refresh` and `api`
 * firewalls, so Symfony's UserCheckerListener runs {@see self::checkPostAuth()}
 * for BOTH the json_login that mints the JWT and every subsequent
 * JWT/refresh-authenticated request. A customer whose portal is switched off is
 * therefore locked out immediately — a still-valid JWT stops working, not just
 * new logins.
 *
 * Only ROLE_PORTAL users are affected; staff logins pass straight through. The
 * check fails closed: a portal account with no linked contact (e.g. revoked),
 * an inactive contact, or a not-yet-enabled customer is all denied.
 */
final class PortalUserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly ContactRepository $contacts,
    ) {}

    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // No pre-credential checks — everything hinges on the resolved identity.
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User || !\in_array('ROLE_PORTAL', $user->getRoles(), true)) {
            return;
        }

        $contact = $this->contacts->findOneBy(['linkedUser' => $user]);
        if (!$contact instanceof Contact
            || !$contact->isActive()
            || !$contact->getCustomer()->isPortalEnabled()
        ) {
            throw new CustomUserMessageAccountStatusException('Portal access is not enabled for this account.');
        }
    }
}
