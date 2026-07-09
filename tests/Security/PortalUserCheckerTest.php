<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ContactRepository;
use App\Security\PortalUserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Login gate for portal users: a ROLE_PORTAL account may only authenticate while
 * its contact is active AND the contact's customer is portal-enabled
 * (Freischaltung). Staff accounts pass straight through.
 */
final class PortalUserCheckerTest extends TestCase
{
    public function testPortalUserWithEnabledCustomerPasses(): void
    {
        $user = $this->portalUser();
        $checker = $this->checker($this->contact($user, active: true, portalEnabled: true));

        $checker->checkPostAuth($user); // no exception
        $this->addToAssertionCount(1);
    }

    public function testPortalUserWithDisabledCustomerIsRejected(): void
    {
        $user = $this->portalUser();
        $checker = $this->checker($this->contact($user, active: true, portalEnabled: false));

        $this->expectException(AccountStatusException::class);
        $checker->checkPostAuth($user);
    }

    public function testPortalUserWithInactiveContactIsRejected(): void
    {
        $user = $this->portalUser();
        $checker = $this->checker($this->contact($user, active: false, portalEnabled: true));

        $this->expectException(AccountStatusException::class);
        $checker->checkPostAuth($user);
    }

    public function testPortalUserWithoutLinkedContactIsRejected(): void
    {
        $checker = $this->checker(null); // e.g. access revoked → contact unlinked

        $this->expectException(AccountStatusException::class);
        $checker->checkPostAuth($this->portalUser());
    }

    public function testStaffUserPassesEvenWithoutContact(): void
    {
        $staff = (new User())->setRoles([]); // getRoles() adds implicit ROLE_USER, no ROLE_PORTAL
        $checker = $this->checker(null);

        $checker->checkPostAuth($staff); // no exception, no contact lookup needed
        $this->addToAssertionCount(1);
    }

    public function testNonAppUserIsIgnored(): void
    {
        $checker = $this->checker(null);
        $checker->checkPostAuth($this->createStub(UserInterface::class)); // no exception
        $this->addToAssertionCount(1);
    }

    // --- helpers ----------------------------------------------------

    private function portalUser(): User
    {
        return (new User())->setEmail('paula@example.test')->setRoles(['ROLE_PORTAL']);
    }

    private function contact(User $user, bool $active, bool $portalEnabled): Contact
    {
        $customer = (new Customer())->setWorkspace(new Workspace())->setPortalEnabled($portalEnabled);

        return (new Contact())
            ->setCustomer($customer)
            ->setFirstName('Paula')->setLastName('Portal')
            ->setLinkedUser($user)
            ->setIsActive($active);
    }

    private function checker(?Contact $contact): PortalUserChecker
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneBy')->willReturn($contact);

        return new PortalUserChecker($contacts);
    }
}
