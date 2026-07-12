<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Shared object-mother + request helpers for the Phase-T guardrail suites
 * (tenant isolation, serialization leaks, portal cross-customer). Extracts the
 * boilerplate that {@see \App\Tests\Functional\TenantScopingTest} and
 * {@see \App\Tests\Functional\Portal\PortalFilesIsolationTest} each hand-rolled,
 * so the new suites stay short. Existing tests are intentionally left untouched.
 *
 * Isolation model (same as the templates): one kernel per test class, every
 * test wrapped in a transaction that {@see self::rollbackTenant()} rolls back —
 * nothing seeded here survives the test.
 */
trait TenantFixtureTrait
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    /** Boot the kernel + open the rollback transaction. Call from setUp(). */
    protected function bootTenant(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    /** Roll the transaction back. Call from tearDown(). */
    protected function rollbackTenant(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
    }

    /** @param array<string, mixed> $settings */
    protected function makeWorkspace(string $prefix, array $settings = []): Workspace
    {
        $ws = (new Workspace())
            ->setName('WS ' . $prefix)
            ->setSlug($prefix . '-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings($settings);
        $this->em->persist($ws);

        return $ws;
    }

    /** @param list<string> $roles */
    protected function makeUser(string $email, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('T')
            ->setLastName('User')
            ->setRoles($roles);
        $user->setPassword('noop');
        $this->em->persist($user);

        return $user;
    }

    protected function makeMember(User $user, Workspace $ws, WorkspaceMemberRole $role = WorkspaceMemberRole::Member): WorkspaceMember
    {
        $member = (new WorkspaceMember())
            ->setUser($user)
            ->setWorkspace($ws)
            ->setRole($role);
        $this->em->persist($member);

        return $member;
    }

    protected function makeCustomer(Workspace $ws, string $name, bool $portalEnabled = false): Customer
    {
        $customer = (new Customer())
            ->setWorkspace($ws)
            ->setName($name)
            ->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)
            ->setPortalEnabled($portalEnabled);
        $this->em->persist($customer);

        return $customer;
    }

    protected function jwt(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * Authenticated request. Adds the Bearer token and JSON-LD Accept header;
     * $server may add/override (e.g. HTTP_HOST for portal routes, content type).
     *
     * @param array<string, mixed> $server
     */
    protected function apiRequest(string $method, string $uri, string $token, array $server = [], ?string $content = null): void
    {
        $this->client->request($method, $uri, [], [], array_merge([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ], $server), $content);
    }

    protected function apiGet(string $uri, string $token, ?string $workspaceId = null): void
    {
        $server = $workspaceId !== null ? ['HTTP_X_WORKSPACE_ID' => $workspaceId] : [];
        $this->apiRequest('GET', $uri, $token, $server);
    }

    /** PATCH with the merge-patch content type API Platform expects. */
    protected function apiPatch(string $uri, string $token, array $payload): void
    {
        $this->apiRequest('PATCH', $uri, $token, [
            'CONTENT_TYPE' => 'application/merge-patch+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    protected function responseStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function rawBody(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    protected function jsonBody(): array
    {
        return json_decode($this->rawBody(), true, 32, \JSON_THROW_ON_ERROR);
    }

    /**
     * The RFC-4122 id of each member of a Hydra/JSON-LD collection response
     * (handles both `hydra:member` and the newer `member` key).
     *
     * @return list<string>
     */
    protected function collectionIds(): array
    {
        $data = $this->jsonBody();
        $members = $data['hydra:member'] ?? $data['member'] ?? [];
        $ids = [];
        foreach ($members as $m) {
            $iri = (string) ($m['@id'] ?? '');
            if ($iri !== '') {
                $ids[] = substr($iri, (int) strrpos($iri, '/') + 1);
            }
        }

        return $ids;
    }
}
