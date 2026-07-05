<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\Customer;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;
use App\Entity\Workspace;
use App\Repository\CustomerRepository;
use App\Repository\EntitySyncRepository;
use App\Repository\InvoiceRepository;
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
 * Inbound MIRROR of lexoffice invoices onto worktide {@see Invoice} rows, for the
 * customer-portal "Rechnungen" tab. For each lexoffice invoice voucher whose
 * contact maps to a worktide customer, upsert an Invoice keyed by the voucher
 * UUID (lexofficeId). Reuses the {@see EntitySync} contactId→Customer mapping
 * written by app:lexoffice:sync-contacts, and the same throttled voucherlist
 * fetch as app:lexoffice:sync-revenue.
 *
 *   bin/console app:lexoffice:sync-invoices [--api-key-file=…] [--workspace=…] [--apply]
 *
 * Inbound-only; reads lexoffice, writes locally inside the SyncReentryGuard.
 * Worktide is not the system of record — re-running re-syncs existing rows.
 */
#[AsCommand(
    name: 'app:lexoffice:sync-invoices',
    description: 'Mirror lexoffice invoices onto worktide Invoice rows (portal Rechnungen tab).',
)]
final class LexofficeSyncInvoicesCommand extends Command
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('api-key-file', null, InputOption::VALUE_REQUIRED, 'File with the lexoffice API key (fallback if the channel has none)')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace UUID (default: the awork CRM workspace)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'lexoffice voucherStatus filter (comma-separated)', 'open,paid,voided')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write (default: dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $status = trim((string) $input->getOption('status')) ?: 'open,paid,voided';

        $workspace = $this->resolveWorkspace($input, $io);
        if (!$workspace instanceof Workspace) {
            $io->error('No workspace found (pass --workspace).');
            return Command::INVALID;
        }

        $channel = $this->em->getRepository(Channel::class)->findOneBy(['workspace' => $workspace, 'adapterCode' => self::ADAPTER]);
        if ($channel === null) {
            $io->error('No lexoffice channel for this workspace — run app:lexoffice:sync-contacts first.');
            return Command::INVALID;
        }
        $apiKey = $channel->getAuthConfig()['apiKey'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            $keyFile = (string) $input->getOption('api-key-file');
            if ($keyFile === '' || !is_file($keyFile)) {
                $io->error('No API key on the lexoffice channel — pass --api-key-file.');
                return Command::INVALID;
            }
            $apiKey = trim((string) file_get_contents($keyFile));
        }
        if ($apiKey === '') {
            $io->error('Empty lexoffice API key.');
            return Command::FAILURE;
        }

        // contactId (lexoffice) → worktide Customer, from the contacts-sync mappings.
        $customerByContactId = [];
        foreach ($this->syncs->findBy(['channel' => $channel, 'entityType' => 'customer']) as $sync) {
            $c = $this->em->find(Customer::class, $sync->getEntityId());
            if ($c !== null && !$c->isDeleted()) {
                $customerByContactId[$sync->getExternalId()] = $c;
            }
        }
        if ($customerByContactId === []) {
            $io->warning('No lexoffice→customer mappings — run app:lexoffice:sync-contacts --apply first.');
            return Command::SUCCESS;
        }

        try {
            $vouchers = $this->fetchInvoices($apiKey, $status);
        } catch (\Throwable $e) {
            $io->error('lexoffice fetch failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->writeln(sprintf('<info>%d invoice vouchers fetched.</info>', \count($vouchers)));

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $run = function () use ($vouchers, $customerByContactId, $apply, &$created, &$updated, &$skipped): void {
            foreach ($vouchers as $v) {
                $contactId = (string) ($v['contactId'] ?? '');
                $customer = $customerByContactId[$contactId] ?? null;
                $lexId = (string) ($v['id'] ?? '');
                if ($customer === null || $lexId === '') {
                    ++$skipped;
                    continue;
                }

                $invoice = $this->invoices->findOneByLexofficeId($lexId);
                if ($invoice === null) {
                    $invoice = (new Invoice())->setLexofficeId($lexId);
                    ++$created;
                } else {
                    ++$updated;
                }

                $invoice
                    ->setCustomer($customer)
                    ->setNumber((string) ($v['voucherNumber'] ?? ''))
                    ->setIssuedOn($this->date($v['voucherDate'] ?? null) ?? new \DateTimeImmutable('today'))
                    ->setDueOn($this->date($v['dueDate'] ?? null))
                    ->setTotalCents((int) round((float) ($v['totalAmount'] ?? 0.0) * 100))
                    ->setOpenCents(isset($v['openAmount']) ? (int) round((float) $v['openAmount'] * 100) : null)
                    ->setCurrency((string) ($v['currency'] ?? 'EUR'))
                    ->setStatus(InvoiceStatus::fromLexoffice((string) ($v['voucherStatus'] ?? 'open')));

                if ($apply) {
                    $this->em->persist($invoice);
                }
            }
            if ($apply) {
                $this->em->flush();
            }
        };

        $apply ? $this->guard->run($run) : $run();

        $io->section($apply ? 'lexoffice-Rechnungen gespiegelt' : 'Dry-Run');
        $io->listing([
            sprintf('Neu: %d', $created),
            sprintf('Aktualisiert: %d', $updated),
            sprintf('Übersprungen (kein Mapping): %d', $skipped),
        ]);
        if (!$apply) {
            $io->note('Dry-Run — nichts geschrieben. Mit --apply ausführen.');
        }

        return Command::SUCCESS;
    }

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

    /**
     * All invoice vouchers across the paginated voucherlist.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchInvoices(string $apiKey, string $status): array
    {
        $out = [];
        $page = 0;
        do {
            $data = $this->fetchPage($apiKey, $status, $page);
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
    private function fetchPage(string $apiKey, string $status, int $page): array
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

    private function date(mixed $raw): ?\DateTimeImmutable
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
}
