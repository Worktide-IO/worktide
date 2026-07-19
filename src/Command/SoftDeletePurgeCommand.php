<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Trait\SoftDeletableTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention purge for soft-deleted rows: permanently removes rows whose
 * `deletedAt` is older than the retention window across every entity using
 * {@see SoftDeletableTrait}. Bounds table growth and frees unique keys held by
 * lingering soft-deleted rows. Schedule daily (Symfony Scheduler / cron).
 */
#[AsCommand(name: 'worktide:soft-delete:purge', description: 'Hard-delete soft-deleted rows older than the retention window')]
final class SoftDeletePurgeCommand extends Command
{
    private const DEFAULT_DAYS = 30;

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention window in days', (string) self::DEFAULT_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report counts without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(0, (int) $input->getOption('days'));
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));

        $io->title(sprintf('Soft-delete purge — cutoff %s%s', $cutoff->format('Y-m-d H:i'), $dryRun ? ' (dry-run)' : ''));

        $total = 0;
        foreach ($this->softDeletableEntities() as $class) {
            $qb = $this->em->createQueryBuilder()
                ->from($class, 'e')
                ->where('e.deletedAt IS NOT NULL')
                ->andWhere('e.deletedAt < :cutoff')
                ->setParameter('cutoff', $cutoff);

            $count = (int) (clone $qb)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
            if ($count === 0) {
                continue;
            }
            $total += $count;

            if ($dryRun) {
                $io->writeln(sprintf('  %-40s %d', $this->shortName($class), $count));
                continue;
            }

            // Bulk DELETE per entity. Runs in the SQL layer (no cascade events);
            // FK ON DELETE rules handle dependents as configured on the schema.
            $deleted = $this->em->createQueryBuilder()
                ->delete($class, 'e')
                ->where('e.deletedAt IS NOT NULL')
                ->andWhere('e.deletedAt < :cutoff')
                ->setParameter('cutoff', $cutoff)
                ->getQuery()
                ->execute();
            $io->writeln(sprintf('  %-40s purged %d', $this->shortName($class), (int) $deleted));
        }

        $io->success(sprintf('%s %d soft-deleted row(s) older than %d day(s).', $dryRun ? 'Would purge' : 'Purged', $total, $days));

        return Command::SUCCESS;
    }

    /** @return list<class-string> */
    private function softDeletableEntities(): array
    {
        $out = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            $class = $meta->getName();
            $traits = [];
            for ($c = $class; $c !== false; $c = get_parent_class($c)) {
                $traits += class_uses($c) ?: [];
            }
            if (isset($traits[SoftDeletableTrait::class])) {
                $out[] = $class;
            }
        }

        return $out;
    }

    private function shortName(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }
}
