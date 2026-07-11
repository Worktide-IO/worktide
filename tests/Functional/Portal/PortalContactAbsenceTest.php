<?php

declare(strict_types=1);

namespace App\Tests\Functional\Portal;

use App\Entity\Contact;
use App\Entity\ContactAbsence;
use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Portal contact absences: a client sets their own away-dates, sees only their
 * own, can't touch another contact's, and the feature is gated. Staff read them
 * workspace-scoped via the ContactAbsence API resource.
 */
final class PortalContactAbsenceTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    public function testCreateListDeleteOwnAbsence(): void
    {
        $ctx = $this->seed(absenceOn: true);
        $token = $this->token($ctx['portalUser']);

        // Create.
        $this->request('POST', '/v1/portal/absences', $token, ['startsOn' => '2026-08-10', 'endsOn' => '2026-08-14', 'note' => 'Urlaub']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = $this->json();
        self::assertSame('2026-08-10', $created['startsOn']);
        self::assertSame('Urlaub', $created['note']);

        // List shows it.
        $this->request('GET', '/v1/portal/absences', $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->json()['absences']);

        // Delete.
        $this->request('DELETE', '/v1/portal/absences/' . $created['id'], $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->request('GET', '/v1/portal/absences', $token);
        self::assertCount(0, $this->json()['absences']);
    }

    public function testFeatureGateBlocksWhenOff(): void
    {
        $ctx = $this->seed(absenceOn: false);
        $this->request('GET', '/v1/portal/absences', $this->token($ctx['portalUser']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCannotDeleteAnotherContactsAbsence(): void
    {
        $ctx = $this->seed(absenceOn: true);
        // Second contact in the same workspace with its own absence. seed() cleared
        // the EM, so re-load the workspace as a managed entity.
        $ws = $this->em->find(Workspace::class, $ctx['ws']->getId());
        $otherUser = $this->user('other.contact@example.test', ['ROLE_PORTAL']);
        $otherCustomer = $this->customer($ws, 'Other GmbH');
        $otherContact = (new Contact())->setCustomer($otherCustomer)->setFirstName('O')->setLastName('C')
            ->setEmail('other.contact@example.test')->setLinkedUser($otherUser);
        $this->em->persist($otherContact);
        $absence = (new ContactAbsence())->setContact($otherContact)
            ->setStartsOn(new \DateTimeImmutable('2026-08-01 12:00'))->setEndsOn(new \DateTimeImmutable('2026-08-02 12:00'));
        $this->em->persist($absence);
        $this->em->flush();

        // The first contact must not be able to delete the other's absence.
        $this->request('DELETE', '/v1/portal/absences/' . $absence->getId()?->toRfc4122(), $this->token($ctx['portalUser']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array{ws: Workspace, portalUser: User, contact: Contact}
     */
    private function seed(bool $absenceOn): array
    {
        $ws = (new Workspace())
            ->setName('WS')->setSlug('ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'absence' => $absenceOn]]]);
        $this->em->persist($ws);

        $portalUser = $this->user('portal.abs@example.test', ['ROLE_PORTAL']);
        $customer = $this->customer($ws, 'Own GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Paula')->setLastName('Portal')
            ->setEmail('portal.abs@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $this->em->flush();
        $this->em->clear();

        return ['ws' => $ws, 'portalUser' => $portalUser, 'contact' => $contact];
    }

    private function user(string $email, array $roles): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('U')->setRoles($roles);
        $user->setPassword('noop');
        $this->em->persist($user);

        return $user;
    }

    private function customer(Workspace $ws, string $name): Customer
    {
        $customer = (new Customer())->setWorkspace($ws)->setName($name)->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)->setPortalEnabled(true);
        $this->em->persist($customer);

        return $customer;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?string $token = null, ?array $body = null): void
    {
        $server = ['HTTP_HOST' => self::HOST, 'CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body, \JSON_THROW_ON_ERROR) : null);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
