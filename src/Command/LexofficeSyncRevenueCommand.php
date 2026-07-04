<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Repository\EntitySyncRepository;
use App\Entity\Workspace;
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
 * Inbound sync of lexoffice invoiced revenue onto worktide customers, feeding
 * the priority score's customer weight (#31). For each lexoffice contact we sum
 * the gross {@see totalAmount} of its invoices in a trailing window and store it
 * on {@see Customer::$revenueCents}; the scorer turns that into a percentile so
 * high-revenue customers' tickets bubble up.
 *
 * Mapping reuses the existing {@see EntitySync} rows written by
 * app:lexoffice:sync-contacts (channel=lexoffice, entityType=customer):
 * externalId (lexoffice contactId) → entityId (worktide customer).
 *
 *   bin/console app:lexoffice:sync-revenue [--api-key-file=…] [--months=12] [--apply]
 *
 * Inbound-only; reads lexoffice, writes locally inside the SyncReentryGuard.
 * Idempotent: every mapped customer's revenue is recomputed for the window on
 * each run (customers with no invoices in the window are reset to 0).
 */
#[AsCommand(
    name: 'app:lexoffice:sync-revenue',
    description: 'Import trailing invoiced revenue from lexoffice onto customers (feeds the priority score).',
)]
final class LexofficeSyncRevenueCommand extends Command
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
            ->addOption('api-key-file', null, InputOption::VALUE_REQUIRED, 'File with the lexoffice API key (fallback if the channel has none)')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace UUID (default: the awork CRM workspace)')
            ->addOption('months', null, InputOption::VALUE_REQUIRED, 'Trailing window in months', '12')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'lexoffice voucherStatus filter (comma-separated)', 'paid,open')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write (default: dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $months = max(1, (int) $input->getOption('months'));
        $status = trim((string) $input->getOption('status')) ?: 'paid,open';

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
        $io->writeln(sprintf('<info>%d mapped customers.</info>', \count($customerByContactId)));

        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d months', $months));

        // Sum gross invoice totals per contact within the window.
        try {
            $grossByContact = $this->sumInvoices($apiKey, $status, $cutoff);
        } catch (\Throwable $e) {
            $io->error('lexoffice fetch failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->writeln(sprintf('<info>Invoices summed for %d contacts (window: last %d months, status: %s).</info>', \count($grossByContact), $months, $status));

        $withRevenue = 0;
        $reset = 0;
        $examples = [];

        $run = function () use ($customerByContactId, $grossByContact, $apply, &$withRevenue, &$reset, &$examples): void {
            $now = new \DateTimeImmutable();
            foreach ($customerByContactId as $contactId => $customer) {
                $gross = $grossByContact[$contactId] ?? 0.0;
                $cents = (int) round($gross * 100);
                if ($cents > 0) {
                    ++$withRevenue;
                    if (\count($examples) < 10) {
                        $examples[] = sprintf('%s: %s €', $customer->getName(), number_format($cents / 100, 2, ',', '.'));
                    }
                } else {
                    ++$reset;
                }
                if ($apply) {
                    $customer->setRevenueCents($cents);
                    $customer->setRevenueSyncedAt($now);
                }
            }
            if ($apply) {
                $this->em->flush();
            }
        };

        $apply ? $this->guard->run($run) : $run();

        $io->section($apply ? 'lexoffice-Umsatz angewandt' : 'Dry-Run');
        $io->listing([
            sprintf('Kunden mit Umsatz im Fenster: %d', $withRevenue),
            sprintf('Kunden ohne Umsatz (auf 0 %s): %d', $apply ? 'gesetzt' : 'zu setzen', $reset),
        ]);
        if ($examples !== []) {
            $io->text('<info>Top-Beispiele:</info> ' . implode(' · ', $examples));
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

    /**
     * @return array<string, float> gross total per lexoffice contactId
     */
    private function sumInvoices(string $apiKey, string $status, \DateTimeImmutable $cutoff): array
    {
        $gross = [];
        $page = 0;
        do {
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
            if ($resp->getStatusCode() >= 400) {
                throw new \RuntimeException('HTTP ' . $resp->getStatusCode() . ' — ' . substr($resp->getContent(false), 0, 200));
            }
            $data = $resp->toArray(false);
            $stop = false;
            foreach (($data['content'] ?? []) as $v) {
                $contactId = (string) ($v['contactId'] ?? '');
                if ($contactId === '') {
                    continue;
                }
                $date = $this->voucherDate($v['voucherDate'] ?? null);
                if ($date !== null && $date < $cutoff) {
                    // Sorted DESC by date → everything after is older; stop paging.
                    $stop = true;
                    continue;
                }
                $amount = (float) ($v['totalAmount'] ?? 0.0);
                $gross[$contactId] = ($gross[$contactId] ?? 0.0) + $amount;
            }
            $last = (bool) ($data['last'] ?? true);
            ++$page;
        } while (!$last && !$stop && $page < 1000);

        return $gross;
    }

    private function voucherDate(mixed $raw): ?\DateTimeImmutable
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
