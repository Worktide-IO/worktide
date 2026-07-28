<?php

declare(strict_types=1);

namespace App\Channels\Adapter;

use App\Channels\InboundAdapter;
use App\Channels\InboundResult;
use App\Channels\SyncReentryGuard;
use App\Channels\TestResult;
use App\Channels\Testable;
use App\Channels\WebhookNotSupportedException;
use App\Entity\Channel;
use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\EntitySync;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\SyncMode;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\Workspace;
use App\Repository\CustomerRepository;
use App\Repository\EntitySyncRepository;
use App\Repository\ProjectRepository;
use App\Service\Inbound\AworkCustomerClassification as C;
use App\Service\Inbound\AworkCustomerClassifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * On-demand inbound sync channel for awork (api.awork.com).
 *
 * v1 scope: companies + projects.
 * TODO v2: tasks + time-entries
 */
final class AworkAdapter implements InboundAdapter, Testable
{
    private const BASE_URL = 'https://api.awork.com/api/v1';
    private const EXTERNAL_SOURCE = 'awork';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly CustomerRepository $customers,
        private readonly EntitySyncRepository $syncs,
        private readonly ProjectRepository $projects,
        private readonly SyncReentryGuard $guard,
        private readonly AworkCustomerClassifier $classifier,
    ) {}

    public function getCode(): string
    {
        return 'awork';
    }

    public function getLabel(): string
    {
        return 'awork';
    }

    public function pull(Channel $channel): InboundResult
    {
        $apiKey = $channel->getAuthConfig()['apiKey'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('No awork API key on channel.');
        }

        $workspace = $channel->getWorkspace();
        $entityTypes = $channel->getEntityTypes();
        if ($entityTypes === []) {
            $entityTypes = ['customer', 'project'];
        }

        $entities = array_map(strtolower(...), $entityTypes);

        $customerMap = [];

        if (\in_array('customer', $entities, true) || \in_array('contact', $entities, true)) {
            $this->guard->run(function () use ($channel, $apiKey, $workspace, &$customerMap): void {
                $customerMap = $this->runCompanySync($channel, $apiKey, $workspace);
            });
        }

        if (\in_array('project', $entities, true)) {
            $this->guard->run(function () use ($channel, $apiKey, $workspace, &$customerMap): void {
                $this->runProjectSync($channel, $apiKey, $workspace, $customerMap);
            });
        }

        return InboundResult::empty();
    }

    public function consumeWebhook(Channel $channel, Request $request): InboundResult
    {
        throw new WebhookNotSupportedException();
    }

    public function selfTest(Channel $channel): TestResult
    {
        $apiKey = $channel->getAuthConfig()['apiKey'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            return TestResult::failed('Kein API-Key hinterlegt');
        }

        try {
            $resp = $this->httpClient->request('GET', self::BASE_URL . '/users/me', [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'timeout' => 5,
            ]);
            $code = $resp->getStatusCode();
            if ($code >= 200 && $code < 300) {
                return TestResult::ok('awork erreichbar');
            }

            return TestResult::failed('HTTP ' . $code);
        } catch (\Throwable $e) {
            return TestResult::failed($e->getMessage());
        }
    }

    // ---- company sync -------------------------------------------------------

    /**
     * @return array<string, Customer> awork companyId → Customer
     */
    private function runCompanySync(Channel $channel, string $apiKey, Workspace $workspace): array
    {
        $companies = $this->fetchFromApi('/companies', $apiKey);
        $customerMap = [];

        /** @var array<string, Customer> $companyByRef */
        $companyByRef = [];

        /**
         * @var list<array{Customer, string, string}> $pairs
         *   [Customer, aw:<uuid>, raw uuid]
         */
        $pairs = [];

        foreach ($companies as $r) {
            $awid = $r['id'] ?? null;
            if (!\is_string($awid) || trim((string) ($r['name'] ?? '')) === '') {
                continue;
            }

            $externalId = self::ref($awid);

            if (($sync = $this->syncs->findOneBy(['channel' => $channel, 'externalId' => $externalId])) !== null) {
                $mapped = $this->em->find(Customer::class, $sync->getEntityId());
                if ($mapped !== null) {
                    $customerMap[$awid] = $mapped;
                }
                continue;
            }

            $class = $this->classifier->classify($r);
            if ($class->kind === C::IGNORE) {
                continue;
            }

            $email = $phone = $website = $address = null;
            foreach (($r['companyContactInfos'] ?? []) as $ci) {
                $type = $ci['type'] ?? null;
                $value = $ci['value'] ?? null;
                if (!\is_string($value) || $value === '') {
                    continue;
                }
                match ($type) {
                    'email' => $email ??= $value,
                    'phone' => $phone ??= $value,
                    'url' => $website ??= $value,
                    'address' => $address ??= $value,
                    default => null,
                };
            }

            if ($class->kind === C::CONTACT) {
                $ref = AworkCustomerClassifier::companyRef((string) $class->companyName);
                $customer = $companyByRef[$ref]
                    ?? $this->customers->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => $ref])
                    ?? $this->customers->findOneBy(['workspace' => $workspace, 'name' => $class->companyName]);

                if ($customer === null) {
                    $customer = (new Customer())
                        ->setWorkspace($workspace)
                        ->setName((string) $class->companyName)
                        ->setIsCompany(true)
                        ->setStatus(CustomerStatus::Active);
                    $customer->setExternalSource(self::EXTERNAL_SOURCE)->setExternalId($ref);
                    $this->em->persist($customer);
                }
                $companyByRef[$ref] = $customer;

                $contact = new Contact();
                $contact->setCustomer($customer)
                    ->setFirstName($class->firstName)
                    ->setLastName($class->lastName !== '' ? $class->lastName : $class->firstName);
                if ($email !== null) {
                    $contact->setEmail($email);
                }
                if ($phone !== null) {
                    $contact->setPhone($phone);
                }
                $this->em->persist($contact);

                $customerMap[$awid] = $customer;
                $pairs[] = [$customer, $externalId, $awid];
            } elseif ($class->kind === C::COMPANY || $class->kind === C::PERSON) {
                $isCompany = $class->kind === C::COMPANY;

                $customer = (new Customer())
                    ->setWorkspace($workspace)
                    ->setIsCompany($isCompany)
                    ->setEmail($email)
                    ->setPhone($phone)
                    ->setWebsite(self::sanitizeUrl($website))
                    ->setAddressLine1($address)
                    ->setStatus(CustomerStatus::Active);

                if ($isCompany) {
                    $customer->setName((string) $class->companyName)
                        ->setLegalName((string) $class->companyName);
                } else {
                    $customer->setFirstName($class->firstName)
                        ->setLastName($class->lastName)
                        ->setName(trim(trim($class->lastName) . ', ' . trim($class->firstName), ', '));
                }

                $customer->setExternalSource(self::EXTERNAL_SOURCE)->setExternalId($externalId);
                $this->em->persist($customer);

                $customerMap[$awid] = $customer;
                $pairs[] = [$customer, $externalId, $awid];
            }
        }

        $this->em->flush();

        foreach ($pairs as [$customer, $exId, $rawId]) {
            if ($customer->getId() === null) {
                continue;
            }
            $sync = (new EntitySync())
                ->setChannel($channel)
                ->setEntityType('customer')
                ->setEntityId($customer->getId())
                ->setExternalId($exId)
                ->setExternalUrl('https://app.awork.com/companies/' . $rawId)
                ->setSyncMode(SyncMode::Inbound);
            $sync->setWorkspace($workspace);
            $this->em->persist($sync);
        }
        $this->em->flush();

        return $customerMap;
    }

    // ---- project sync -------------------------------------------------------

    /**
     * @param array<string, Customer> $customerMap awork companyId → Customer
     */
    private function runProjectSync(Channel $channel, string $apiKey, Workspace $workspace, array $customerMap): void
    {
        $projects = $this->fetchFromApi('/projects', $apiKey, ['orderby' => 'tasksCount desc']);
        $statusMap = $this->fetchProjectStatusMap($apiKey, $workspace);

        /**
         * @var list<array{Project, string, string}> $pairs
         *   [Project, aw:<uuid>, raw uuid]
         */
        $pairs = [];

        foreach ($projects as $p) {
            $awid = $p['id'] ?? null;
            if (!\is_string($awid) || trim((string) ($p['name'] ?? '')) === '') {
                continue;
            }

            $externalId = self::ref($awid);

            if ($this->syncs->findOneBy(['channel' => $channel, 'externalId' => $externalId]) !== null) {
                continue;
            }

            $statusId = $p['projectStatusId'] ?? null;
            $status = \is_string($statusId) ? ($statusMap[$statusId] ?? null) : null;
            if ($status === null) {
                $status = $this->em->getRepository(ProjectStatus::class)->findOneBy(['workspace' => $workspace]);
                if ($status === null) {
                    continue;
                }
            }

            $project = new Project();
            $key = $p['projectKey'] ?? strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $p['name'] ?? 'P') ?? 'P', 0, 6));

            $project
                ->setWorkspace($workspace)
                ->setName($p['name'] ?? 'Untitled')
                ->setKey($key)
                ->setDescription(($p['description'] ?? '') === '' ? null : (string) $p['description'])
                ->setStatus($status)
                ->setIsPrivate((bool) ($p['isPrivate'] ?? false))
                ->setIsBillableByDefault((bool) ($p['isBillableByDefault'] ?? false))
                ->setDeductNonBillableHours((bool) ($p['deductNonBillableHours'] ?? false))
                ->setIsRetainer((bool) ($p['isRetainer'] ?? false))
                ->setIsMultiAssignmentAllowed((bool) ($p['isMultiAssignmentAllowed'] ?? true))
                ->setBudgetMinutes($this->secondsToMinutes($p['timeBudget'] ?? null))
                ->setIsArchived(false);

            $companyId = $p['companyId'] ?? null;
            if (\is_string($companyId) && isset($customerMap[$companyId])) {
                $project->setCustomer($customerMap[$companyId]);
            }

            $project->setExternalSource(self::EXTERNAL_SOURCE)->setExternalId($externalId);
            $this->em->persist($project);

            $pairs[] = [$project, $externalId, $awid];
        }

        $this->em->flush();

        foreach ($pairs as [$project, $exId, $rawId]) {
            if ($project->getId() === null) {
                continue;
            }
            $sync = (new EntitySync())
                ->setChannel($channel)
                ->setEntityType('project')
                ->setEntityId($project->getId())
                ->setExternalId($exId)
                ->setExternalUrl('https://app.awork.com/projects/' . $rawId)
                ->setSyncMode(SyncMode::Inbound);
            $sync->setWorkspace($workspace);
            $this->em->persist($sync);
        }
        $this->em->flush();
    }

    // ---- fetching ------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchFromApi(string $path, string $apiKey, array $extraQuery = []): array
    {
        $all = [];
        $page = 1;
        $pageSize = 250;

        do {
            $query = array_merge($extraQuery, ['page' => $page, 'pageSize' => $pageSize]);
            $resp = $this->httpClient->request('GET', self::BASE_URL . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'query' => $query,
                'timeout' => 30,
            ]);

            if ($resp->getStatusCode() >= 400) {
                throw new \RuntimeException('HTTP ' . $resp->getStatusCode() . ' while fetching ' . $path);
            }

            $data = $resp->toArray(false);
            if (!\is_array($data)) {
                break;
            }

            foreach ($data as $item) {
                if (\is_array($item)) {
                    $all[] = $item;
                }
            }

            if (\count($data) < $pageSize) {
                break;
            }
            ++$page;
        } while ($page < 1000);

        return $all;
    }

    /**
     * @return array<string, ProjectStatus> awork projectStatusId → ProjectStatus
     */
    private function fetchProjectStatusMap(string $apiKey, Workspace $workspace): array
    {
        $raw = $this->fetchFromApi('/projectstatuses', $apiKey);
        $repo = $this->em->getRepository(ProjectStatus::class);
        $map = [];
        // awork keeps a separate status set per project-type, so the same status
        // NAME (e.g. "Geblockt") recurs under many ids. ProjectStatus is unique
        // per (workspace, name) — a DB findOneBy alone misses a sibling created
        // earlier in THIS run but not yet flushed, and inserting a second one
        // then trips the unique constraint. Cache by name within the run.
        $byName = [];

        foreach ($raw as $r) {
            $awid = $r['id'] ?? null;
            $name = trim((string) ($r['name'] ?? ''));
            $type = $r['type'] ?? 'progress';
            if (!\is_string($awid) || $name === '') {
                continue;
            }

            $status = $byName[$name]
                ?? $repo->findOneBy(['workspace' => $workspace, 'name' => $name]);
            if ($status === null) {
                $status = (new ProjectStatus())
                    ->setWorkspace($workspace)
                    ->setName($name)
                    ->setColor($this->mapColor($r['color'] ?? null, '#94a3b8'))
                    ->setIsCompleted($type === 'closed')
                    ->setPosition(0);
                $status->setExternalSource(self::EXTERNAL_SOURCE)->setExternalId(self::ref($awid));
                $this->em->persist($status);
            }
            $byName[$name] = $status;
            $map[$awid] = $status;
        }

        $this->em->flush();

        return $map;
    }

    // ---- helpers -------------------------------------------------------------

    private static function ref(string $aworkUuid): string
    {
        return 'aw:' . $aworkUuid;
    }

    private static function sanitizeUrl(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $value) !== 1) {
            $value = 'https://' . $value;
        }

        return filter_var($value, \FILTER_VALIDATE_URL) === false ? null : $value;
    }

    private static function secondsToMinutes(mixed $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }
        $s = (int) $seconds;
        if ($s <= 0) {
            return 0;
        }

        return (int) round($s / 60);
    }

    private function mapColor(?string $awColor, string $default): string
    {
        if (!\is_string($awColor) || $awColor === '') {
            return $default;
        }

        return str_starts_with($awColor, '#') ? $awColor : "#$awColor";
    }
}
