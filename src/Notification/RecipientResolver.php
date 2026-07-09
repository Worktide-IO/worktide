<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Customer;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Shared recipient lookups for the notification resolvers: parse user IRIs and
 * enumerate the portal-eligible users behind a customer.
 */
final class RecipientResolver
{
    public function __construct(
        private readonly UserRepository $users,
    ) {}

    public function userFromIri(string $iri): ?User
    {
        if (!preg_match('#/v1/users/([0-9a-f-]{36})#', $iri, $m)) {
            return null;
        }

        return $this->userFromUuidString($m[1]);
    }

    public function userFromUuidString(string $uuid): ?User
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        return $this->users->find(Uuid::fromString($uuid));
    }

    /**
     * The portal users behind a customer: active contacts that have a linked
     * portal login. Deduplicated by user id. (Whether the customer's portal is
     * switched on at all is enforced upstream at login/read time, not here.)
     *
     * @return list<User>
     */
    public function portalUsersOfCustomer(Customer $customer): array
    {
        $out = [];
        foreach ($customer->getContacts() as $contact) {
            $user = $contact->getLinkedUser();
            if ($user === null || !$contact->isActive()) {
                continue;
            }
            $id = $user->getId()?->toRfc4122();
            if ($id !== null) {
                $out[$id] = $user;
            }
        }

        return array_values($out);
    }
}
