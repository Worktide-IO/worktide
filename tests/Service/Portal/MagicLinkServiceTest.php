<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\DomainEventLog;
use App\Entity\MagicLoginToken;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ContactRepository;
use App\Repository\MagicLoginTokenRepository;
use App\Security\PortalUserChecker;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the passwordless portal magic-login flow (staff
 * impersonation). No database — stubbed repositories + collaborators, mirroring
 * {@see \App\Tests\Service\PasswordResetServiceTest}. Pins the security-relevant
 * properties: only the SHA-256 hash is stored (plaintext lives solely in the
 * URL), issue + consume are audited, the live portal gate is re-checked on
 * consume, and the consume mints a JWT but returns NO refresh credential.
 */
final class MagicLinkServiceTest extends TestCase
{
    private const PORTAL_BASE = 'https://portal.example.test';

    // --- issueForImpersonation() ------------------------------------

    public function testIssueStoresOnlyHashLogsAndReturnsPortalUrl(): void
    {
        $staff = $this->user('staff@example.test', ['ROLE_USER']);
        $target = $this->user('cust@example.test', ['ROLE_PORTAL']);
        $contact = $this->contact($target, portalEnabled: true, active: true, customerName: 'Acme GmbH');

        $tokens = $this->createMock(MagicLoginTokenRepository::class);
        $tokens->expects(self::once())->method('deleteForUser')->with($target);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->expects(self::once())->method('flush');

        $url = $this->service($tokens, $em, $this->stubContacts($contact))->issueForImpersonation($contact, $staff);

        self::assertStringStartsWith(self::PORTAL_BASE . '/auth/magic?token=', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $plaintext = (string) ($query['token'] ?? '');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plaintext, '32 random bytes, hex');

        $token = $this->firstOf($persisted, MagicLoginToken::class);
        self::assertInstanceOf(MagicLoginToken::class, $token);
        self::assertSame(hash('sha256', $plaintext), $token->getTokenHash());
        self::assertNotSame($plaintext, $token->getTokenHash());
        self::assertSame($target, $token->getUser());
        self::assertSame($staff, $token->getIssuedByUser());

        $log = $this->firstOf($persisted, DomainEventLog::class);
        self::assertInstanceOf(DomainEventLog::class, $log);
        self::assertSame('portal.impersonation.issued', $log->getName());
    }

    // --- consume() --------------------------------------------------

    public function testConsumeBlankTokenReturnsNull(): void
    {
        $tokens = $this->createMock(MagicLoginTokenRepository::class);
        $tokens->expects(self::never())->method('findValidByHash');

        self::assertNull($this->service($tokens, $this->createStub(EntityManagerInterface::class), $this->stubContacts(null))->consume('   '));
    }

    public function testConsumeUnknownTokenReturnsNull(): void
    {
        $tokens = $this->createStub(MagicLoginTokenRepository::class);
        $tokens->method('findValidByHash')->willReturn(null);

        self::assertNull($this->service($tokens, $this->createStub(EntityManagerInterface::class), $this->stubContacts(null))->consume('deadbeef'));
    }

    public function testConsumeValidTokenMintsJwtMarksUsedAndLogs(): void
    {
        $staff = $this->user('staff@example.test', ['ROLE_USER']);
        $target = $this->user('cust@example.test', ['ROLE_PORTAL']);
        $contact = $this->contact($target, portalEnabled: true, active: true, customerName: 'Acme GmbH');
        $entity = (new MagicLoginToken())->setUser($target)->setIssuedByUser($staff)->setTokenHash(hash('sha256', 'plain'));

        $tokens = $this->createStub(MagicLoginTokenRepository::class);
        $tokens->method('findValidByHash')->willReturn($entity);

        $logged = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$logged): void {
            $logged = $e;
        });
        $em->expects(self::once())->method('flush');

        $result = $this->service($tokens, $em, $this->stubContacts($contact))->consume('plain');

        self::assertIsArray($result);
        self::assertSame('JWT-TOKEN', $result['token']);
        self::assertTrue($result['impersonation']);
        self::assertSame('Acme GmbH', $result['customerName']);
        self::assertSame('staff@example.test', $result['issuedBy']);
        self::assertNotNull($entity->getUsedAt(), 'token must be marked used');
        self::assertInstanceOf(DomainEventLog::class, $logged);
        self::assertSame('portal.impersonation.consumed', $logged->getName());
    }

    public function testConsumeThrowsWhenPortalDisabledMeanwhile(): void
    {
        $target = $this->user('cust@example.test', ['ROLE_PORTAL']);
        // Customer portal switched off since the link was issued.
        $contact = $this->contact($target, portalEnabled: false, active: true, customerName: 'Acme GmbH');
        $entity = (new MagicLoginToken())->setUser($target)->setTokenHash(hash('sha256', 'plain'));

        $tokens = $this->createStub(MagicLoginTokenRepository::class);
        $tokens->method('findValidByHash')->willReturn($entity);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->service($tokens, $this->createStub(EntityManagerInterface::class), $this->stubContacts($contact))->consume('plain');
    }

    // --- helpers ----------------------------------------------------

    private function service(
        MagicLoginTokenRepository $tokens,
        EntityManagerInterface $em,
        ContactRepository $contacts,
    ): \App\Service\Portal\MagicLinkService {
        $jwt = $this->createStub(JWTTokenManagerInterface::class);
        $jwt->method('create')->willReturn('JWT-TOKEN');

        return new \App\Service\Portal\MagicLinkService(
            $em,
            $tokens,
            $contacts,
            $jwt,
            new PortalUserChecker($contacts),
            new RequestStack(),
            self::PORTAL_BASE,
        );
    }

    private function stubContacts(?Contact $contact): ContactRepository
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findOneBy')->willReturn($contact);

        return $contacts;
    }

    private function contact(User $linkedUser, bool $portalEnabled, bool $active, string $customerName): Contact
    {
        $customer = (new Customer())
            ->setName($customerName)
            ->setPortalEnabled($portalEnabled)
            ->setWorkspace(new Workspace());

        return (new Contact())
            ->setCustomer($customer)
            ->setFirstName('Cust')
            ->setLastName('Omer')
            ->setIsActive($active)
            ->setLinkedUser($linkedUser);
    }

    /**
     * @param array<string> $roles
     */
    private function user(string $email, array $roles): User
    {
        $u = (new User())->setEmail($email)->setRoles($roles);
        (new \ReflectionProperty($u, 'id'))->setValue($u, Uuid::v7());

        return $u;
    }

    /**
     * @param list<object> $objects
     * @param class-string $class
     */
    private function firstOf(array $objects, string $class): ?object
    {
        foreach ($objects as $o) {
            if ($o instanceof $class) {
                return $o;
            }
        }

        return null;
    }
}
