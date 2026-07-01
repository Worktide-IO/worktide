<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\SyncReentryGuard;
use App\Entity\Contact;
use App\Entity\Customer;
use App\Repository\CustomerRepository;
use App\Service\Inbound\AworkCustomerClassification as C;
use App\Service\Inbound\AworkCustomerClassifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the company→contact-person hierarchy that awork's flat model lost,
 * using the shared {@see AworkCustomerClassifier} (+ an overrides file). Fixes
 * the already-imported flat records; the awork import uses the same classifier
 * so a re-import stays clean.
 *
 *   bin/console app:awork:rebuild-customer-hierarchy [--apply] [--overrides=…]
 *
 * Idempotent: derived companies get a deterministic externalId
 * ({@see AworkCustomerClassifier::companyRef()}), contacts/persons are upserted
 * by their awork id — so re-runs converge. Default is dry-run; --apply writes.
 */
#[AsCommand(
    name: 'app:awork:rebuild-customer-hierarchy',
    description: 'Reconstruct company→contact hierarchy from the flat awork customer import.',
)]
final class AworkRebuildCustomerHierarchyCommand extends Command
{
    private const EXTERNAL_SOURCE = 'awork';

    /** Entities whose `customer` FK must follow a demoted record to its company. */
    private const CUSTOMER_RELATIONS = [
        \App\Entity\Project::class,
        \App\Entity\CustomerSystem::class,
        \App\Entity\ServiceSubscription::class,
        \App\Entity\CustomerAgreement::class,
        \App\Entity\CustomerProduct::class,
        \App\Entity\Conversation::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CustomerRepository $customers,
        private readonly SyncReentryGuard $guard,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('snapshot-dir', null, InputOption::VALUE_REQUIRED, 'awork snapshot dir', 'var/awork-snapshot')
            ->addOption('overrides', null, InputOption::VALUE_REQUIRED, 'Overrides JSON (default: <snapshot-dir>/customer-overrides.json)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Execute the rebuild (destructive)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        $snapshotDir = $this->projectDir . '/' . $input->getOption('snapshot-dir');
        $file = $snapshotDir . '/companies.json';
        if (!is_file($file)) {
            $io->error("companies.json not found at $file");
            return Command::FAILURE;
        }
        $overridesPath = $input->getOption('overrides') ?? ($snapshotDir . '/customer-overrides.json');
        $classifier = AworkCustomerClassifier::fromFile($overridesPath);
        $io->text(is_file($overridesPath) ? "Overrides: $overridesPath" : 'Overrides: (keine)');

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode((string) file_get_contents($file), true) ?: [];

        $anyCustomer = $this->customers->findOneBy(['externalSource' => self::EXTERNAL_SOURCE]);
        if (!$anyCustomer instanceof Customer) {
            $io->error('No awork-sourced customers found.');
            return Command::FAILURE;
        }
        $workspace = $anyCustomer->getWorkspace();

        $byExternalId = [];
        $byName = [];
        foreach ($this->customers->findBy(['workspace' => $workspace]) as $c) {
            if ($c->getExternalId() !== null) {
                $byExternalId[$c->getExternalId()] = $c;
            }
            $byName[mb_strtolower($c->getName())] = $c;
        }

        /** @var array<string, array{display: string, self: ?string, members: list<array{awId: string, first: string, last: string, row: array<string,mixed>}>}> $companies */
        $companies = [];
        /** @var list<array{awId: string, first: string, last: string}> $persons */
        $persons = [];
        $ignored = 0;

        foreach ($rows as $r) {
            if (!isset($r['id'])) {
                continue;
            }
            $awId = 'aw:' . $r['id'];
            $c = $classifier->classify($r);
            switch ($c->kind) {
                case C::IGNORE:
                    ++$ignored;
                    break;
                case C::PERSON:
                    $persons[] = ['awId' => $awId, 'first' => $c->firstName, 'last' => $c->lastName];
                    break;
                case C::CONTACT:
                    $key = mb_strtolower((string) $c->companyName);
                    $companies[$key] ??= ['display' => (string) $c->companyName, 'self' => null, 'members' => []];
                    $companies[$key]['members'][] = ['awId' => $awId, 'first' => $c->firstName, 'last' => $c->lastName, 'row' => $r];
                    break;
                default: // COMPANY
                    $key = mb_strtolower((string) $c->companyName);
                    $companies[$key] ??= ['display' => (string) $c->companyName, 'self' => null, 'members' => []];
                    $companies[$key]['self'] = $awId;
            }
        }

        $resolveFirma = function (array $c) use ($byExternalId, $byName): ?Customer {
            if ($c['self'] !== null && isset($byExternalId[$c['self']])) {
                return $byExternalId[$c['self']];
            }
            return $byExternalId[AworkCustomerClassifier::companyRef($c['display'])]
                ?? $byName[mb_strtolower($c['display'])]
                ?? null;
        };

        $newCompanies = 0;
        $contactCount = 0;
        $relinkNotes = [];
        foreach ($companies as $c) {
            $resolveFirma($c) !== null ?: $newCompanies++;
            foreach ($c['members'] as $m) {
                ++$contactCount;
                $old = $byExternalId[$m['awId']] ?? null;
                if ($old !== null) {
                    $linked = $this->countLinked($old);
                    if ($linked > 0) {
                        $relinkNotes[] = sprintf('%s %s → %s (%d verknüpft)', $m['first'], $m['last'], $c['display'], $linked);
                    }
                }
            }
        }

        $io->section('Rekonstruktions-Plan');
        $io->listing([
            sprintf('Firmen: %d (neu: %d, bestehend: %d)', \count($companies), $newCompanies, \count($companies) - $newCompanies),
            sprintf('Ansprechpartner (Contacts): %d', $contactCount),
            sprintf('Person-Kunden: %d', \count($persons)),
            sprintf('Ignoriert (Overrides): %d', $ignored),
        ]);
        $examples = array_slice(array_filter($companies, static fn ($c) => $c['members'] !== []), 0, 8);
        foreach ($examples as $c) {
            $io->text(sprintf('  <comment>%s</comment>: %s', $c['display'], implode(', ', array_map(static fn ($m) => trim($m['first'] . ' ' . $m['last']), $c['members']))));
        }
        if ($relinkNotes !== []) {
            $io->warning("Umzuhängende Daten:\n - " . implode("\n - ", $relinkNotes));
        }

        if (!$apply) {
            $io->note('Dry-Run — nichts geändert. Mit --apply ausführen.');
            return Command::SUCCESS;
        }

        $contactRepo = $this->em->getRepository(Contact::class);
        $this->guard->run(function () use ($companies, $persons, $workspace, $byExternalId, $resolveFirma, $contactRepo): void {
            // Phase 1: create/normalise all Firma-Customers and flush, so they
            // have DB ids before the (immediate) relink UPDATEs reference them.
            $firmas = [];
            foreach ($companies as $key => $c) {
                $firma = $resolveFirma($c);
                if ($firma === null) {
                    $firma = (new Customer())
                        ->setWorkspace($workspace)
                        ->setName($c['display'])
                        ->setIsCompany(true);
                    $firma->setExternalSource(self::EXTERNAL_SOURCE)->setExternalId(AworkCustomerClassifier::companyRef($c['display']));
                    $this->em->persist($firma);
                } else {
                    $firma->setIsCompany(true)->setName($c['display']);
                }
                $firmas[$key] = $firma;
            }
            $this->em->flush();

            // Phase 2: contacts, relink demoted records' data, soft-delete them,
            // and convert persons.
            foreach ($companies as $key => $c) {
                $firma = $firmas[$key];
                foreach ($c['members'] as $m) {
                    $contact = $contactRepo->findOneBy(['externalSource' => self::EXTERNAL_SOURCE, 'externalId' => $m['awId']])
                        ?? new Contact();
                    $contact->setCustomer($firma)->setFirstName($m['first'])->setLastName($m['last'] !== '' ? $m['last'] : $m['first']);
                    $contact->setExternalSource(self::EXTERNAL_SOURCE)->setExternalId($m['awId']);
                    [$email, $phone] = $this->extractContactInfo($m['row']);
                    if ($email !== null) {
                        $contact->setEmail($email);
                    }
                    if ($phone !== null) {
                        $contact->setPhone($phone);
                    }
                    $this->em->persist($contact);

                    $old = $byExternalId[$m['awId']] ?? null;
                    if ($old !== null && $old !== $firma) {
                        $this->relinkCustomer($old, $firma);
                        $old->softDelete();
                    }
                }
            }
            foreach ($persons as $p) {
                $cust = $byExternalId[$p['awId']] ?? null;
                if ($cust === null) {
                    continue;
                }
                $cust->setIsCompany(false)->setFirstName($p['first'])->setLastName($p['last']);
            }
            $this->em->flush();
        });

        $io->success('Rekonstruktion angewandt.');

        return Command::SUCCESS;
    }

    private function countLinked(Customer $c): int
    {
        $n = 0;
        foreach (self::CUSTOMER_RELATIONS as $class) {
            $n += (int) $this->em->createQuery("SELECT COUNT(e.id) FROM $class e WHERE e.customer = :c")
                ->setParameter('c', $c)->getSingleScalarResult();
        }

        return $n;
    }

    private function relinkCustomer(Customer $from, Customer $to): void
    {
        foreach ([...self::CUSTOMER_RELATIONS, Contact::class] as $class) {
            $this->em->createQuery("UPDATE $class e SET e.customer = :to WHERE e.customer = :from")
                ->setParameter('to', $to)->setParameter('from', $from)->execute();
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{0: ?string, 1: ?string} [email, phone]
     */
    private function extractContactInfo(array $row): array
    {
        $email = null;
        $phone = null;
        foreach (($row['companyContactInfos'] ?? []) as $ci) {
            $val = \is_array($ci) ? trim((string) ($ci['value'] ?? $ci['contact'] ?? '')) : '';
            if ($val === '') {
                continue;
            }
            if ($email === null && filter_var($val, \FILTER_VALIDATE_EMAIL)) {
                $email = $val;
            } elseif ($phone === null && preg_match('/[0-9]{4,}/', $val)) {
                $phone = $val;
            }
        }

        return [$email, $phone];
    }
}
