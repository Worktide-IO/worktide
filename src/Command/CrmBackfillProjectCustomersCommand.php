<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Customer;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills the customer link on projects that have none, using the most
 * reliable signal available per project:
 *
 *   1. awork company id — for awork-imported projects, read the project's
 *      snapshot `companyId` and map it to the Customer (externalSource=awork,
 *      externalId=aw:<companyId>). Deterministic.
 *   2. exact name match — a single Customer whose name equals the project name
 *      (case/whitespace-insensitive).
 *   3. normalized name match — a single Customer whose name matches after
 *      stripping legal suffixes (GmbH, AG, e.V., …) and punctuation.
 *
 * Only fills NULL customer links (never overwrites), so it is idempotent and
 * safe to re-run. Dry-run by default — pass --apply to write.
 */
#[AsCommand(
    name: 'app:crm:backfill-project-customers',
    description: 'Assign customers to projects that have none (awork companyId, then name match).',
)]
final class CrmBackfillProjectCustomersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the assignments (default: dry-run).')
            ->addOption('snapshot-dir', null, InputOption::VALUE_REQUIRED, 'awork project snapshot directory', 'var/awork-snapshot/projects');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $snapshotDir = rtrim((string) $input->getOption('snapshot-dir'), '/');

        /** @var list<Project> $projects */
        $projects = $this->em->getRepository(Project::class)->findBy(['customer' => null]);
        if ($projects === []) {
            $io->success('All projects already have a customer.');

            return Command::SUCCESS;
        }

        // Index all customers by exact + normalized name (workspace-agnostic;
        // narrowed to the project's workspace at match time).
        /** @var list<Customer> $customers */
        $customers = $this->em->getRepository(Customer::class)->findAll();
        $byExternal = [];      // "aw:<id>" => Customer
        $byExact = [];         // ws => [normLowerName => [Customer, …]]
        $byNormalized = [];    // ws => [normalized => [Customer, …]]
        foreach ($customers as $c) {
            $ws = $c->getWorkspace()->getId()?->toRfc4122() ?? '';
            if ($c->getExternalSource() !== null && $c->getExternalId() !== null) {
                $byExternal[$c->getExternalId()] = $c;
            }
            $byExact[$ws][mb_strtolower(trim($c->getName()))][] = $c;
            $byNormalized[$ws][self::normalize($c->getName())][] = $c;
        }

        $rows = [];
        $matched = 0;
        $bySource = ['awork' => 0, 'name-exact' => 0, 'name-normalized' => 0];

        foreach ($projects as $project) {
            $ws = $project->getWorkspace()->getId()?->toRfc4122() ?? '';
            [$customer, $source] = $this->resolve($project, $ws, $snapshotDir, $byExternal, $byExact, $byNormalized);

            if ($customer !== null) {
                ++$matched;
                ++$bySource[$source];
                $rows[] = [mb_substr($project->getName(), 0, 34), mb_substr($customer->getName(), 0, 34), $source];
                if ($apply) {
                    $project->setCustomer($customer);
                }
            } else {
                $rows[] = [mb_substr($project->getName(), 0, 34), '—', 'UNMATCHED'];
            }
        }

        $io->table(['Project', 'Customer', 'Source'], $rows);
        $io->writeln(sprintf(
            'Matched %d / %d unassigned (awork: %d, name-exact: %d, name-normalized: %d). Unmatched: %d.',
            $matched,
            count($projects),
            $bySource['awork'],
            $bySource['name-exact'],
            $bySource['name-normalized'],
            count($projects) - $matched,
        ));

        if (!$apply) {
            $io->note('Dry-run — nothing written. Re-run with --apply to persist.');

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('Applied %d customer assignments.', $matched));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, Customer>                    $byExternal
     * @param array<string, array<string, list<Customer>>> $byExact
     * @param array<string, array<string, list<Customer>>> $byNormalized
     *
     * @return array{0: ?Customer, 1: string}
     */
    private function resolve(
        Project $project,
        string $ws,
        string $snapshotDir,
        array $byExternal,
        array $byExact,
        array $byNormalized,
    ): array {
        // 1. awork company id via the project's snapshot.
        if ($project->getExternalSource() === 'awork' && ($ext = $project->getExternalId()) !== null) {
            $awUuid = str_starts_with($ext, 'aw:') ? substr($ext, 3) : $ext;
            $file = $snapshotDir . '/' . $awUuid . '.json';
            if (is_file($file)) {
                $snapshot = json_decode((string) file_get_contents($file), true);
                $companyId = \is_array($snapshot) ? ($snapshot['companyId'] ?? ($snapshot['company']['id'] ?? null)) : null;
                if (\is_string($companyId) && isset($byExternal['aw:' . $companyId])) {
                    return [$byExternal['aw:' . $companyId], 'awork'];
                }
            }
        }

        // 2. exact name match (unique, same workspace).
        $exactKey = mb_strtolower(trim($project->getName()));
        if (isset($byExact[$ws][$exactKey]) && \count($byExact[$ws][$exactKey]) === 1) {
            return [$byExact[$ws][$exactKey][0], 'name-exact'];
        }

        // 3. normalized name match (unique, same workspace).
        $normKey = self::normalize($project->getName());
        if ($normKey !== '' && isset($byNormalized[$ws][$normKey]) && \count($byNormalized[$ws][$normKey]) === 1) {
            return [$byNormalized[$ws][$normKey][0], 'name-normalized'];
        }

        return [null, 'UNMATCHED'];
    }

    /**
     * Normalize a company/project name for fuzzy matching: lowercase, drop legal
     * suffixes and common project qualifiers, strip punctuation, collapse space.
     */
    private static function normalize(string $name): string
    {
        $s = mb_strtolower(trim($name));
        // Drop trailing project qualifiers like "- Intern", "- Relaunch", "(abgeschlossen)".
        $s = preg_replace('/\s*[-–—]\s*(intern|relaunch|launch|migration|designrelaunch|abgeschlossen).*$/u', '', $s) ?? $s;
        // Drop legal-form suffixes.
        $s = preg_replace('/\b(gmbh(\s*&\s*co\.?\s*kg)?|mbh|ag|e\.?\s*v\.?|kg|ohg|gbr|ug|ev|inc|ltd|corp|co\.?\s*kg)\b/u', '', $s) ?? $s;
        // Strip remaining punctuation, collapse whitespace.
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }
}
