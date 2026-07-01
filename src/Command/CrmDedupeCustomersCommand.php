<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\SyncReentryGuard;
use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\EntitySync;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Merges duplicate customer records that share an identical (case-insensitive)
 * name within a workspace — a byproduct of external systems (lexoffice) holding
 * several records for the same entity. The surviving record absorbs the losers'
 * relations (projects, systems, subscriptions, agreements, products,
 * conversations, contacts) AND their external mappings ({@see EntitySync}, so a
 * single Worktide customer can carry multiple lexoffice ids — the M:N the sync
 * model is built for); empty scalar fields are filled from the loser and the
 * role flags are OR-merged. Losers are soft-deleted.
 *
 * Companies (identical company name = strong identity) are merged. Persons are
 * riskier (same surname ≠ same person), so a person group is only merged when
 * it is confidently one entity: at least one member carries a signal (email or
 * normalised address) and every signal-bearer agrees on it (empty stubs fold
 * in). Person groups with conflicting or absent signals are listed for review,
 * never auto-merged.
 *
 *   bin/console app:crm:dedupe-customers [--apply]
 *
 * Default is a dry-run; --apply writes inside the {@see SyncReentryGuard}.
 */
#[AsCommand(
    name: 'app:crm:dedupe-customers',
    description: 'Merge duplicate customers (same name); companies by default, persons with --include-persons.',
)]
final class CrmDedupeCustomersCommand extends Command
{
    /** Entities whose `customer` FK must follow a merged-away record to the survivor. */
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Execute the merge (destructive)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        // Group non-deleted customers by (workspace, case-insensitive name, isCompany).
        /** @var array<string, list<Customer>> $groups */
        $groups = [];
        foreach ($this->customers->findBy(['deletedAt' => null]) as $c) {
            $ws = $c->getWorkspace()->getId()?->toRfc4122() ?? '';
            $key = $ws . '|' . ($c->isCompany() ? 'c' : 'p') . '|' . mb_strtolower(trim($c->getName()));
            $groups[$key][] = $c;
        }
        $dupGroups = array_filter($groups, static fn (array $g) => \count($g) > 1);

        $companyGroups = [];
        $personGroups = [];
        foreach ($dupGroups as $g) {
            if ($g[0]->isCompany()) {
                $companyGroups[] = $g;
            } else {
                $personGroups[] = $g;
            }
        }

        // Persons are only merged when the group is confidently one person: at
        // least one member carries a signal (email or normalised address) and
        // ALL signal-bearers agree on it (empty stubs fold into them). Groups
        // with conflicting signals — or no signal at all — are left for review.
        $safePersons = [];
        $reviewPersons = [];
        foreach ($personGroups as $g) {
            if ($this->personIsSameEntity($g)) {
                $safePersons[] = $g;
            } else {
                $reviewPersons[] = $g;
            }
        }

        $io->section('Duplikat-Analyse');
        $io->listing([
            sprintf('Firmen-Gruppen: %d (%d redundante Datensätze)', \count($companyGroups), $this->redundant($companyGroups)),
            sprintf('Personen-Gruppen sicher: %d (%d redundante Datensätze)', \count($safePersons), $this->redundant($safePersons)),
            sprintf('Personen-Gruppen zur Sichtung: %d', \count($reviewPersons)),
        ]);

        if ($reviewPersons !== []) {
            $io->warning("Personen mit widersprüchlichem/fehlendem Signal — NICHT gemerged (manuell prüfen):");
            $io->listing(array_map(static fn (array $g) => $g[0]->getName() . ' (' . \count($g) . '×)', $reviewPersons));
        }

        $toMerge = array_merge($companyGroups, $safePersons);
        if ($toMerge === []) {
            $io->success('Nichts zu mergen.');
            return Command::SUCCESS;
        }

        // Plan preview.
        foreach (\array_slice($toMerge, 0, 15) as $g) {
            $survivor = $this->chooseSurvivor($g);
            $losers = array_filter($g, static fn (Customer $c) => $c !== $survivor);
            $io->text(sprintf(
                '  <info>%s</info> ← %d Dublette(n) einschmelzen',
                $survivor->getName(),
                \count($losers),
            ));
        }

        if (!$apply) {
            $io->note('Dry-Run — nichts geändert. Mit --apply ausführen.');
            return Command::SUCCESS;
        }

        $merged = 0;
        $this->guard->run(function () use ($toMerge, &$merged): void {
            foreach ($toMerge as $g) {
                $survivor = $this->chooseSurvivor($g);
                foreach ($g as $c) {
                    if ($c === $survivor) {
                        continue;
                    }
                    $this->merge($survivor, $c);
                    ++$merged;
                }
            }
            $this->em->flush();
        });

        $io->success(sprintf('%d Dublette(n) in %d Gruppen zusammengeführt.', $merged, \count($toMerge)));

        return Command::SUCCESS;
    }

    /** @param list<list<Customer>> $groups */
    private function redundant(array $groups): int
    {
        return array_sum(array_map(static fn (array $g) => \count($g) - 1, $groups));
    }

    /**
     * Survivor = most linked relations, then most non-empty contact fields,
     * then the lexicographically smallest id (deterministic tie-break).
     *
     * @param list<Customer> $group
     */
    private function chooseSurvivor(array $group): Customer
    {
        usort($group, function (Customer $a, Customer $b): int {
            return [$this->countLinked($b), $this->richness($b), $b->getId()?->toRfc4122() ?? '']
                <=> [$this->countLinked($a), $this->richness($a), $a->getId()?->toRfc4122() ?? ''];
        });

        return $group[0];
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

    private function richness(Customer $c): int
    {
        return (int) ($c->getEmail() !== null && $c->getEmail() !== '')
            + (int) ($c->getVatId() !== null && $c->getVatId() !== '')
            + (int) ($c->getPhone() !== null && $c->getPhone() !== '')
            + (int) ($c->getAddressLine1() !== null && $c->getAddressLine1() !== '')
            + (int) ($c->getCity() !== null && $c->getCity() !== '');
    }

    /**
     * A same-name person group is one entity iff every "identified" member (one
     * that carries an email or a normalised address) links into a SINGLE cluster
     * — two members link when they agree on email OR on address. Pure stubs (no
     * signal) fold in. An all-stub group, or identified members that split into
     * ≥2 unlinked clusters (conflicting or non-overlapping signals, e.g. one
     * address-only + one email-only record), is left for review.
     *
     * @param list<Customer> $group
     */
    private function personIsSameEntity(array $group): bool
    {
        /** @var list<array{e: ?string, a: ?string}> $ids */
        $ids = [];
        foreach ($group as $c) {
            $e = $this->emailSig($c);
            $a = $this->addrSig($c);
            if ($e !== null || $a !== null) {
                $ids[] = ['e' => $e, 'a' => $a];
            }
        }
        $n = \count($ids);
        if ($n === 0) {
            return false; // all stubs — cannot confirm same person
        }

        // Connected components via label propagation (groups are tiny).
        $label = range(0, $n - 1);
        do {
            $changed = false;
            for ($i = 0; $i < $n; ++$i) {
                for ($j = $i + 1; $j < $n; ++$j) {
                    $link = ($ids[$i]['e'] !== null && $ids[$i]['e'] === $ids[$j]['e'])
                        || ($ids[$i]['a'] !== null && $ids[$i]['a'] === $ids[$j]['a']);
                    if ($link && $label[$i] !== $label[$j]) {
                        $old = max($label[$i], $label[$j]);
                        $new = min($label[$i], $label[$j]);
                        foreach ($label as $k => $v) {
                            if ($v === $old) {
                                $label[$k] = $new;
                            }
                        }
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return \count(array_unique($label)) === 1;
    }

    private function emailSig(Customer $c): ?string
    {
        $email = $c->getEmail();

        return $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
    }

    private function addrSig(Customer $c): ?string
    {
        $addr = $this->normToken((string) $c->getAddressLine1()) . '|' . $this->normToken($this->stripCitySuffix((string) $c->getCity()));

        return trim($addr, '|') === '' ? null : $addr;
    }

    private function normToken(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = str_replace(['strasse', 'straße', 'str.'], 'str', $s);

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }

    /** Drop German locality qualifiers ("i. Bay.", "a. Rhein", "am Main") before normalising. */
    private function stripCitySuffix(string $city): string
    {
        return preg_replace('/\b(i|a|am|an|im|der|bay|rhein)\b\.?/iu', ' ', $city) ?? $city;
    }

    private function merge(Customer $survivor, Customer $loser): void
    {
        // Re-point relations + external mappings from loser → survivor.
        foreach ([...self::CUSTOMER_RELATIONS, Contact::class] as $class) {
            $this->em->createQuery("UPDATE $class e SET e.customer = :to WHERE e.customer = :from")
                ->setParameter('to', $survivor)->setParameter('from', $loser)->execute();
        }
        $this->em->createQuery(\sprintf('UPDATE %s es SET es.entityId = :to WHERE es.entityId = :from AND es.entityType = :type', EntitySync::class))
            ->setParameter('to', $survivor->getId())
            ->setParameter('from', $loser->getId())
            ->setParameter('type', 'customer')
            ->execute();

        // Fill empty survivor scalars from the loser.
        if ($this->blank($survivor->getEmail()) && !$this->blank($loser->getEmail())) {
            $survivor->setEmail($loser->getEmail());
        }
        if ($this->blank($survivor->getVatId()) && !$this->blank($loser->getVatId())) {
            $survivor->setVatId($loser->getVatId());
        }
        if ($this->blank($survivor->getPhone()) && !$this->blank($loser->getPhone())) {
            $survivor->setPhone($loser->getPhone());
        }
        if ($this->blank($survivor->getLegalName()) && !$this->blank($loser->getLegalName())) {
            $survivor->setLegalName($loser->getLegalName());
        }
        if ($this->blank($survivor->getAddressLine1()) && !$this->blank($loser->getAddressLine1())) {
            $survivor->setAddressLine1($loser->getAddressLine1());
            $survivor->setAddressLine2($loser->getAddressLine2());
            $survivor->setZip($loser->getZip());
            $survivor->setCity($loser->getCity());
            $survivor->setCountry($loser->getCountry());
        }

        // OR-merge the relationship-type flags.
        $survivor->setIsCustomer($survivor->isCustomer() || $loser->isCustomer());
        $survivor->setIsVendor($survivor->isVendor() || $loser->isVendor());

        $loser->softDelete();
    }

    private function blank(?string $v): bool
    {
        return $v === null || trim($v) === '';
    }
}
