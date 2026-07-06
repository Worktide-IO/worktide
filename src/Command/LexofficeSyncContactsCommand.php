<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\EntitySync;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\SyncMode;
use App\Entity\Workspace;
use App\Repository\CustomerRepository;
use App\Repository\EntitySyncRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Inbound sync of lexoffice contacts as a second data source. lexoffice is a
 * separate system, so the mapping lives in {@see EntitySync} (channel=lexoffice)
 * — a record can carry both its awork and lexoffice ids at once.
 *
 * Matching (link, don't duplicate): each lexoffice contact is matched to an
 * existing worktide customer by e-mail → USt-ID → name; a hit gets an EntitySync
 * mapping (and fills empty fields), a miss creates a new customer. lexoffice
 * `company.contactPersons` become worktide Contacts. Persons stay person
 * customers, companies stay companies.
 *
 *   bin/console app:lexoffice:sync-contacts [--api-key-file=…] [--apply]
 *
 * Inbound-only; reads lexoffice, writes locally inside the SyncReentryGuard.
 */
#[AsCommand(
    name: 'app:lexoffice:sync-contacts',
    description: 'Match/import lexoffice contacts into worktide customers (inbound).',
)]
final class LexofficeSyncContactsCommand extends Command
{
    private const ADAPTER = 'lexoffice';
    private const BASE_URL = 'https://api.lexoffice.io/v1';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly CustomerRepository $customers,
        private readonly EntitySyncRepository $syncs,
        private readonly SyncReentryGuard $guard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('api-key-file', null, InputOption::VALUE_REQUIRED, 'File with the lexoffice API key (first run only; then stored on the channel)')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace UUID (default: the awork CRM workspace)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write (default: dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $workspace = $this->resolveWorkspace($input, $io);
        if (!$workspace instanceof Workspace) {
            return Command::INVALID;
        }

        $channel = $this->em->getRepository(Channel::class)->findOneBy(['workspace' => $workspace, 'adapterCode' => self::ADAPTER]);
        $apiKey = $channel?->getAuthConfig()['apiKey'] ?? null;
        if ($apiKey === null) {
            $keyFile = (string) $input->getOption('api-key-file');
            if ($keyFile === '' || !is_file($keyFile)) {
                $io->error('No lexoffice channel yet — pass --api-key-file on the first run.');
                return Command::INVALID;
            }
            $apiKey = trim((string) file_get_contents($keyFile));
        }
        if ($apiKey === '') {
            $io->error('Empty lexoffice API key.');
            return Command::FAILURE;
        }

        // Fetch all lexoffice contacts (paginated).
        try {
            $contacts = $this->fetchContacts($apiKey, $io);
        } catch (\Throwable $e) {
            $io->error('lexoffice fetch failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->writeln(sprintf('<info>%d lexoffice contacts fetched.</info>', \count($contacts)));

        // Index existing worktide customers for matching.
        [$byEmail, $byVat, $byName] = $this->indexCustomers($workspace);

        $matched = 0;
        $created = 0;
        $alreadyMapped = 0;
        $examplesMatched = [];
        $examplesNew = [];

        $run = function () use ($contacts, $workspace, &$channel, $apiKey, $byEmail, $byVat, $byName, $apply, &$matched, &$created, &$alreadyMapped, &$examplesMatched, &$examplesNew): void {
            if ($apply && $channel === null) {
                $channel = $this->createChannel($workspace, $apiKey);
                $this->em->flush();
            }

            /** @var list<array{0: Customer, 1: string}> $pairs customer + lexoffice id to map */
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

                if ($channel !== null && ($sync = $this->syncs->findOneBy(['channel' => $channel, 'externalId' => $lxId])) !== null) {
                    ++$alreadyMapped;
                    if ($apply) {
                        // Backfill relationship type onto the already-mapped record.
                        $mapped = $this->em->find(Customer::class, $sync->getEntityId());
                        if ($mapped !== null) {
                            $this->applyRoles($mapped, $lx);
                        }
                    }
                    continue;
                }

                $match = $this->match($emails, $vat, $name, $isCompany, $byEmail, $byVat, $byName);
                if ($match !== null) {
                    ++$matched;
                    if (\count($examplesMatched) < 8) {
                        $examplesMatched[] = sprintf('%s ↔ %s', $name, $match->getName());
                    }
                    if ($apply) {
                        $this->fillEmpty($match, $lx, $emails, $vat);
                        $this->applyRoles($match, $lx);
                        $pairs[] = [$match, $lxId];
                    }
                } else {
                    ++$created;
                    if (\count($examplesNew) < 8) {
                        $examplesNew[] = $name;
                    }
                    if ($apply) {
                        $pairs[] = [$this->createCustomer($workspace, $lx, $isCompany, $name, $emails, $vat), $lxId];
                    }
                }
            }

            if (!$apply || $channel === null) {
                return;
            }
            $this->em->flush(); // new customers get ids
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
        };

        $apply ? $this->guard->run($run) : $run();

        $io->section($apply ? 'lexoffice-Sync angewandt' : 'Dry-Run');
        $io->listing([
            sprintf('Zu bestehenden Kunden gematcht: %d', $matched),
            sprintf('Neu %s: %d', $apply ? 'angelegt' : 'anzulegen', $created),
            sprintf('Bereits gemappt (übersprungen): %d', $alreadyMapped),
        ]);
        if ($examplesMatched !== []) {
            $io->text('<info>Matches:</info> ' . implode(' · ', $examplesMatched));
        }
        if ($examplesNew !== []) {
            $io->text('<info>Neu (Beispiele):</info> ' . implode(', ', $examplesNew));
        }
        if (!$apply) {
            $io->note('Dry-Run — nichts geändert. Mit --apply ausführen.');
        }

        return Command::SUCCESS;
    }

    // ---- helpers -----------------------------------------------------------

    private function resolveWorkspace(InputInterface $input, SymfonyStyle $io): ?Workspace
    {
        $wsOpt = (string) $input->getOption('workspace');
        if ($wsOpt !== '') {
            try {
                $ws = $this->em->find(Workspace::class, Uuid::fromString($wsOpt));
            } catch (\InvalidArgumentException) {
                $io->error('--workspace is not a valid UUID.');
                return null;
            }
            return $ws instanceof Workspace ? $ws : null;
        }
        $anchor = $this->customers->findOneBy(['externalSource' => 'awork']);

        return $anchor?->getWorkspace();
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchContacts(string $apiKey, SymfonyStyle $io): array
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
                $byName[$norm] ??= $c; // extra key: legal-form/umlaut-folded
            }
        }

        return [$byEmail, $byVat, $byName];
    }

    /**
     * Fold a company/person name to a comparison key: lowercase, umlaut-fold,
     * drop trailing legal-form tokens (GmbH, AG, e.K., …), strip punctuation,
     * collapse whitespace. Conservative — only trailing legal forms are removed,
     * so distinct names don't collapse into one another.
     */
    private function normalizeName(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e']);
        $s = str_replace('&', ' ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        // Strip trailing legal-form tokens (possibly several: "… gmbh co kg").
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
        // Type-aware: a company only matches a company, a person only a person.
        $ok = static fn (?Customer $c): ?Customer => ($c !== null && $c->isCompany() === $wantCompany) ? $c : null;

        // NOTE: e-mail is deliberately NOT a match key — shared/agency addresses
        // in the small existing set produce cross-matches. USt-ID → name only.
        if ($vat !== null && $vat !== '') {
            $key = mb_strtolower(preg_replace('/\s+/', '', $vat) ?? '');
            $hit = $ok($byVat[$key] ?? null);
            if ($hit !== null) {
                return $hit;
            }
        }

        // Exact name first, then legal-form/umlaut-folded key as a fallback.
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
        // The record's OWN e-mails only — deliberately NOT the contactPersons'
        // addresses (a shared/agency address on a contact person caused
        // cross-matches). Contact-person mails are used for Contacts, not matching.
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

    /**
     * Mirror lexoffice roles onto the relationship-type flags. For lexoffice-origin
     * records the roles are authoritative (set exactly); for matched foreign records
     * (awork, …) they are additive — an awork project customer stays a customer even
     * if lexoffice only lists it as a vendor.
     *
     * @param array<string, mixed> $lx
     */
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

        // lexoffice assigns a human customer number once a contact has the
        // customer role (roles.customer.number). Mirror it regardless of the
        // record's origin slot so awork-matched customers also show it.
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
        // Marker on freshly-created records: makes the single-slot ref point at
        // lexoffice (matched awork customers keep their awork slot; their
        // lexoffice identity lives only in EntitySync). Enables a clean rollback
        // (DELETE … WHERE external_source='lexoffice') and a second idempotency
        // path on re-runs.
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

        // lexoffice company contactPersons → Ansprechpartner.
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

    private function createChannel(Workspace $workspace, string $apiKey): Channel
    {
        $channel = (new Channel())
            ->setName('lexoffice')
            ->setAdapterCode(self::ADAPTER)
            ->setCapabilities(['inbound'])
            ->setEntityTypes(['customer', 'contact'])
            ->setInboundConfig(['baseUrl' => self::BASE_URL])
            ->setAuthConfig(['apiKey' => $apiKey])
            ->setIsEnabled(false)
            ->setIsShared(false);
        $channel->setWorkspace($workspace);
        $this->em->persist($channel);

        return $channel;
    }
}
