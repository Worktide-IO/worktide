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
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Enum\SyncMode;
use App\Entity\Invoice;
use App\Entity\Workspace;
use App\Repository\CustomerRepository;
use App\Repository\EntitySyncRepository;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wraps the lexoffice CLI sync logic (contacts, invoices, revenue) as an
 * on-demand InboundAdapter channel. The lexoffice API key is read from
 * {@see Channel::$authConfig} and the scope (which sub-syncs run) is
 * driven by {@see Channel::$entityTypes}.
 *
 * Idempotent re-run — existing mappings and upserted Invoice rows are
 * skipped or refreshed on each pull.
 */
final class LexofficeAdapter implements InboundAdapter, Testable
{
    private const ADAPTER = 'lexoffice';
    private const BASE_URL = 'https://api.lexoffice.io/v1';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly CustomerRepository $customers,
        private readonly EntitySyncRepository $syncs,
        private readonly InvoiceRepository $invoices,
        private readonly SyncReentryGuard $guard,
    ) {}

    public function getCode(): string
    {
        return 'lexoffice';
    }

    public function getLabel(): string
    {
        return 'Lexoffice';
    }

    public function pull(Channel $channel): InboundResult
    {
        $apiKey = $channel->getAuthConfig()['apiKey'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('No Lexoffice API key on channel.');
        }

        $workspace = $channel->getWorkspace();
        $entityTypes = $channel->getEntityTypes();
        if ($entityTypes === []) {
            $entityTypes = ['contact'];
        }

        $entities = array_map(strtolower(...), $entityTypes);

        if (in_array('customer', $entities, true) || in_array('contact', $entities, true)) {
            $this->guard->run(function () use ($channel, $apiKey, $workspace): void {
                $this->runContactSync($channel, $apiKey, $workspace);
            });
        }

        if (in_array('invoice', $entities, true)) {
            $this->guard->run(function () use ($channel, $apiKey, $workspace): void {
                $this->runInvoiceSync($channel, $apiKey, $workspace);
            });
        }

        if (in_array('revenue', $entities, true)) {
            $this->guard->run(function () use ($channel, $apiKey, $workspace): void {
                $this->runRevenueSync($channel, $apiKey, $workspace);
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
            $resp = $this->httpClient->request('GET', self::BASE_URL . '/contacts', [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'query' => ['page' => 0, 'size' => 1],
                'timeout' => 5,
            ]);
            $code = $resp->getStatusCode();
            if ($code >= 200 && $code < 300) {
                return TestResult::ok('Lexoffice erreichbar');
            }

            return TestResult::failed('HTTP ' . $code);
        } catch (\Throwable $e) {
            return TestResult::failed($e->getMessage());
        }
    }

    // ---- contact sync ------------------------------------------------------

    private function runContactSync(Channel $channel, string $apiKey, Workspace $workspace): void
    {
        $contacts = $this->fetchContacts($apiKey);
        [$byEmail, $byVat, $byName] = $this->indexCustomers($workspace);

        /** @var list<array{0: Customer, 1: string}> $pairs */
        $pairs = [];
        foreach ($contacts as $lx) {
            $lxId = (string) ($lx['id'] ?? '');
            if ($lxId === '') {
                continue;
            }
            $isCompany = isset($lx['company']);
            $name = $this->contactName($lx);
            if ($name === '') {
                continue;
            }
            $emails = $this->emails($lx);
            $vat = isset($lx['company']['vatRegistrationId']) ? trim((string) $lx['company']['vatRegistrationId']) : null;

            if (($sync = $this->syncs->findOneBy(['channel' => $channel, 'externalId' => $lxId])) !== null) {
                $mapped = $this->em->find(Customer::class, $sync->getEntityId());
                if ($mapped !== null) {
                    $this->applyRoles($mapped, $lx);
                }
                continue;
            }

            $match = $this->match($emails, $vat, $name, $isCompany, $byEmail, $byVat, $byName);
            if ($match !== null) {
                $this->fillEmpty($match, $lx, $emails, $vat);
                $this->applyRoles($match, $lx);
                $pairs[] = [$match, $lxId];
            } else {
                $pairs[] = [$this->createCustomer($workspace, $lx, $isCompany, $name, $emails, $vat), $lxId];
            }
        }

        $this->em->flush();
        foreach ($pairs as [$customer, $lxId]) {
            if ($customer->getId() === null) {
                continue;
            }
            $sync = (new EntitySync())
                ->setChannel($channel)
                ->setEntityType('customer')
                ->setEntityId($customer->getId())
                ->setExternalId($lxId)
                ->setExternalUrl('https://app.lexoffice.de/contacts/#/' . $lxId)
                ->setSyncMode(SyncMode::Inbound);
            $sync->setWorkspace($workspace);
            $this->em->persist($sync);
        }
        $this->em->flush();
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchContacts(string $apiKey): array
    {
        $all = [];
        $page = 0;
        do {
            $resp = $this->httpClient->request('GET', self::BASE_URL . '/contacts', [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'query' => ['page' => $page, 'size' => 250],
                'timeout' => 30,
            ]);
            if ($resp->getStatusCode() >= 400) {
                throw new \RuntimeException('HTTP ' . $resp->getStatusCode());
            }
            $data = $resp->toArray(false);
            foreach (($data['content'] ?? []) as $c) {
                $all[] = $c;
            }
            $last = (bool) ($data['last'] ?? true);
            ++$page;
        } while (!$last && $page < 1000);

        return $all;
    }

    /**
     * @return array{0: array<string,Customer>, 1: array<string,Customer>, 2: array<string,Customer>}
     */
    private function indexCustomers(Workspace $workspace): array
    {
        $byEmail = $byVat = $byName = [];
        foreach ($this->customers->findBy(['workspace' => $workspace]) as $c) {
            if ($c->isDeleted()) {
                continue;
            }
            if ($c->getEmail() !== null && $c->getEmail() !== '') {
                $byEmail[mb_strtolower($c->getEmail())] ??= $c;
            }
            if ($c->getVatId() !== null && $c->getVatId() !== '') {
                $byVat[mb_strtolower(preg_replace('/\s+/', '', $c->getVatId()) ?? '')] ??= $c;
            }
            $byName[mb_strtolower(trim($c->getName()))] ??= $c;
            $norm = $this->normalizeName($c->getName());
            if ($norm !== '') {
                $byName[$norm] ??= $c;
            }
        }

        return [$byEmail, $byVat, $byName];
    }

    private function normalizeName(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e']);
        $s = str_replace('&', ' ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        $forms = ['gmbh', 'mbh', 'ag', 'kg', 'ohg', 'gbr', 'ug', 'ek', 'ev', 'co', 'kgaa', 'se', 'ggmbh', 'haftungsbeschraenkt', 'partg', 'mbb'];
        $parts = $s === '' ? [] : explode(' ', $s);
        while ($parts !== [] && \in_array(end($parts), $forms, true)) {
            array_pop($parts);
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<string>              $emails
     * @param array<string, Customer>   $byEmail
     * @param array<string, Customer>   $byVat
     * @param array<string, Customer>   $byName
     */
    private function match(array $emails, ?string $vat, string $name, bool $wantCompany, array $byEmail, array $byVat, array $byName): ?Customer
    {
        $ok = static fn (?Customer $c): ?Customer => ($c !== null && $c->isCompany() === $wantCompany) ? $c : null;

        if ($vat !== null && $vat !== '') {
            $key = mb_strtolower(preg_replace('/\s+/', '', $vat) ?? '');
            $hit = $ok($byVat[$key] ?? null);
            if ($hit !== null) {
                return $hit;
            }
        }

        return $ok($byName[mb_strtolower(trim($name))] ?? null)
            ?? $ok($byName[$this->normalizeName($name)] ?? null);
    }

    /** @param array<string, mixed> $lx */
    private function contactName(array $lx): string
    {
        if (isset($lx['company']['name'])) {
            return trim((string) $lx['company']['name']);
        }
        if (isset($lx['person'])) {
            return trim(trim((string) ($lx['person']['lastName'] ?? '')) . ', ' . trim((string) ($lx['person']['firstName'] ?? '')), ', ');
        }

        return '';
    }

    /**
     * @param array<string, mixed> $lx
     *
     * @return list<string>
     */
    private function emails(array $lx): array
    {
        $out = [];
        foreach ((array) ($lx['emailAddresses'] ?? []) as $group) {
            foreach ((array) $group as $addr) {
                if (\is_string($addr) && $addr !== '') {
                    $out[] = $addr;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $lx */
    private function fillEmpty(Customer $c, array $lx, array $emails, ?string $vat): void
    {
        if (($c->getEmail() === null || $c->getEmail() === '') && $emails !== []) {
            $c->setEmail($emails[0]);
        }
        if (($c->getVatId() === null || $c->getVatId() === '') && $vat !== null && $vat !== '') {
            $c->setVatId($vat);
        }
        $addr = $lx['addresses']['billing'][0] ?? null;
        if (\is_array($addr) && ($c->getCity() === null || $c->getCity() === '')) {
            $c->setAddressLine1(trim((string) ($addr['street'] ?? '')) ?: null)
                ->setZip(trim((string) ($addr['zip'] ?? '')) ?: null)
                ->setCity(trim((string) ($addr['city'] ?? '')) ?: null)
                ->setCountry(($addr['countryCode'] ?? null) ? strtoupper((string) $addr['countryCode']) : null);
        }
    }

    /** @param array<string, mixed> $lx */
    private function applyRoles(Customer $c, array $lx): void
    {
        $roles = array_keys($lx['roles'] ?? []);
        $isCust = \in_array('customer', $roles, true);
        $isVend = \in_array('vendor', $roles, true);
        if ($c->getExternalSource() === self::ADAPTER) {
            $c->setIsCustomer($isCust)->setIsVendor($isVend);
        } else {
            $c->setIsCustomer($c->isCustomer() || $isCust)->setIsVendor($c->isVendor() || $isVend);
        }

        $number = $lx['roles']['customer']['number'] ?? null;
        if (is_numeric($number) || (\is_string($number) && trim($number) !== '')) {
            $c->setCustomerNumber((string) $number);
        }
    }

    /** @param array<string, mixed> $lx */
    private function createCustomer(Workspace $workspace, array $lx, bool $isCompany, string $name, array $emails, ?string $vat): Customer
    {
        $customer = (new Customer())
            ->setWorkspace($workspace)
            ->setIsCompany($isCompany)
            ->setStatus(CustomerStatus::Active);
        $customer->setExternalSource(self::ADAPTER)
            ->setExternalId((string) ($lx['id'] ?? ''));
        $this->applyRoles($customer, $lx);
        if ($isCompany) {
            $customer->setName($name)->setLegalName($name);
        } else {
            $customer->setFirstName(trim((string) ($lx['person']['firstName'] ?? '')))
                ->setLastName(trim((string) ($lx['person']['lastName'] ?? '')))
                ->setName($name);
        }
        if ($emails !== []) {
            $customer->setEmail($emails[0]);
        }
        if ($vat !== null && $vat !== '') {
            $customer->setVatId($vat);
        }
        $addr = $lx['addresses']['billing'][0] ?? null;
        if (\is_array($addr)) {
            $customer->setAddressLine1(trim((string) ($addr['street'] ?? '')) ?: null)
                ->setZip(trim((string) ($addr['zip'] ?? '')) ?: null)
                ->setCity(trim((string) ($addr['city'] ?? '')) ?: null)
                ->setCountry(($addr['countryCode'] ?? null) ? strtoupper((string) $addr['countryCode']) : null);
        }
        $this->em->persist($customer);

        if ($isCompany) {
            foreach ((array) ($lx['company']['contactPersons'] ?? []) as $cp) {
                $first = trim((string) ($cp['firstName'] ?? ''));
                $last = trim((string) ($cp['lastName'] ?? ''));
                if ($first === '' && $last === '') {
                    continue;
                }
                $contact = (new Contact())
                    ->setCustomer($customer)
                    ->setFirstName($first)
                    ->setLastName($last !== '' ? $last : $first);
                if (!empty($cp['emailAddress'])) {
                    $contact->setEmail((string) $cp['emailAddress']);
                }
                $this->em->persist($contact);
            }
        }

        return $customer;
    }

    // ---- invoice sync ------------------------------------------------------

    private function runInvoiceSync(Channel $channel, string $apiKey, Workspace $workspace): void
    {
        $customerByContactId = $this->buildCustomerMap($channel);
        if ($customerByContactId === []) {
            return;
        }

        $vouchers = $this->fetchInvoices($apiKey);

        foreach ($vouchers as $v) {
            $contactId = (string) ($v['contactId'] ?? '');
            $customer = $customerByContactId[$contactId] ?? null;
            $lexId = (string) ($v['id'] ?? '');
            if ($customer === null || $lexId === '') {
                continue;
            }

            $invoice = $this->invoices->findOneByLexofficeId($lexId);
            if ($invoice === null) {
                $invoice = (new Invoice())->setLexofficeId($lexId);
            }

            $invoice
                ->setCustomer($customer)
                ->setNumber((string) ($v['voucherNumber'] ?? ''))
                ->setIssuedOn($this->dateFromString($v['voucherDate'] ?? null) ?? new \DateTimeImmutable('today'))
                ->setDueOn($this->dateFromString($v['dueDate'] ?? null))
                ->setTotalCents((int) round((float) ($v['totalAmount'] ?? 0.0) * 100))
                ->setOpenCents(isset($v['openAmount']) ? (int) round((float) $v['openAmount'] * 100) : null)
                ->setCurrency((string) ($v['currency'] ?? 'EUR'))
                ->setStatus(InvoiceStatus::fromLexoffice((string) ($v['voucherStatus'] ?? 'open')));

            $this->em->persist($invoice);
        }
        $this->em->flush();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchInvoices(string $apiKey): array
    {
        $out = [];
        $page = 0;
        $status = 'open,paid,voided';
        do {
            $data = $this->fetchVoucherlistPage($apiKey, $status, $page);
            foreach (($data['content'] ?? []) as $v) {
                $out[] = $v;
            }
            $last = (bool) ($data['last'] ?? true);
            ++$page;
        } while (!$last && $page < 1000);

        return $out;
    }

    /**
     * One voucherlist page, throttled and 429-resilient (~2 req/s limit).
     *
     * @return array<string, mixed>
     */
    private function fetchVoucherlistPage(string $apiKey, string $status, int $page): array
    {
        for ($attempt = 0; ; ++$attempt) {
            usleep(600_000);
            $resp = $this->httpClient->request('GET', self::BASE_URL . '/voucherlist', [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                'query' => [
                    'voucherType' => 'invoice',
                    'voucherStatus' => $status,
                    'page' => $page,
                    'size' => 250,
                    'sort' => 'voucherDate,DESC',
                ],
                'timeout' => 30,
            ]);
            $code = $resp->getStatusCode();
            if ($code === 429 && $attempt < 5) {
                sleep(2 << $attempt);
                continue;
            }
            if ($code >= 400) {
                throw new \RuntimeException('HTTP ' . $code . ' — ' . substr($resp->getContent(false), 0, 200));
            }

            return $resp->toArray(false);
        }
    }

    private function dateFromString(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    // ---- revenue sync ------------------------------------------------------

    private function runRevenueSync(Channel $channel, string $apiKey, Workspace $workspace): void
    {
        $customerByContactId = $this->buildCustomerMap($channel);
        if ($customerByContactId === []) {
            return;
        }

        $grossByContact = $this->sumInvoices($apiKey);

        $now = new \DateTimeImmutable();
        foreach ($customerByContactId as $contactId => $customer) {
            $gross = $grossByContact[$contactId] ?? 0.0;
            $cents = (int) round($gross * 100);
            $customer->setRevenueCents($cents);
            $customer->setRevenueSyncedAt($now);
        }
        $this->em->flush();
    }

    /**
     * @return array<string, float> gross total per lexoffice contactId
     */
    private function sumInvoices(string $apiKey): array
    {
        $gross = [];
        $page = 0;
        $status = 'paid,open';
        do {
            $data = $this->fetchVoucherlistPage($apiKey, $status, $page);
            foreach (($data['content'] ?? []) as $v) {
                $contactId = (string) ($v['contactId'] ?? '');
                if ($contactId === '') {
                    continue;
                }
                $amount = (float) ($v['totalAmount'] ?? 0.0);
                $gross[$contactId] = ($gross[$contactId] ?? 0.0) + $amount;
            }
            $last = (bool) ($data['last'] ?? true);
            ++$page;
        } while (!$last && $page < 1000);

        return $gross;
    }

    // ---- shared -------------------------------------------------------------

    /**
     * contactId (lexoffice) → worktide Customer, from the contacts-sync mappings.
     *
     * @return array<string, Customer>
     */
    private function buildCustomerMap(Channel $channel): array
    {
        $map = [];
        foreach ($this->syncs->findBy(['channel' => $channel, 'entityType' => 'customer']) as $sync) {
            $c = $this->em->find(Customer::class, $sync->getEntityId());
            if ($c !== null && !$c->isDeleted()) {
                $map[$sync->getExternalId()] = $c;
            }
        }

        return $map;
    }
}
