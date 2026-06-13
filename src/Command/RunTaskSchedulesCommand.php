<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\TaskPriority;
use App\Entity\Task;
use App\Entity\TaskSchedule;
use App\Entity\TaskStatus;
use App\Repository\TaskScheduleRepository;
use App\Repository\TaskStatusRepository;
use Cron\CronExpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Walks active TaskSchedules whose nextRunAt has passed and materialises
 * a new Task in each schedule's project. Re-computes nextRunAt from the
 * schedule's cron expression so the next firing is queued.
 *
 * Run from cron (or systemd timer) every minute in production:
 *   * * * * * cd /var/www/worktide && bin/console app:tasks:run-schedules
 *
 * Idempotent — running multiple times in the same minute will only create
 * one task per schedule per cron tick because nextRunAt advances on each run.
 */
#[AsCommand(
    name: 'app:tasks:run-schedules',
    description: 'Materialise recurring tasks whose TaskSchedule.nextRunAt has elapsed.',
)]
final class RunTaskSchedulesCommand extends Command
{
    public function __construct(
        private readonly TaskScheduleRepository $schedules,
        private readonly TaskStatusRepository $taskStatuses,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without creating tasks.')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Override "now" for testing (ISO-8601).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $nowOption = $input->getOption('now');
        $now = \is_string($nowOption) && $nowOption !== ''
            ? new \DateTimeImmutable($nowOption)
            : new \DateTimeImmutable();

        /** @var list<TaskSchedule> $due */
        $due = $this->schedules->createQueryBuilder('s')
            ->andWhere('s.isEnabled = true')
            ->andWhere('s.nextRunAt IS NULL OR s.nextRunAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        if ($due === []) {
            $io->info(sprintf('No schedules due at %s.', $now->format(\DateTimeInterface::ATOM)));
            return Command::SUCCESS;
        }

        $created = 0;
        foreach ($due as $schedule) {
            $project = $schedule->getProject();
            $status = $this->resolveDefaultTaskStatus($project->getWorkspace());
            if ($status === null) {
                $io->warning(sprintf('Skipping "%s": no default task status in workspace.', $schedule->getName()));
                continue;
            }

            $offset = $this->nextTaskOffset($project);
            $priority = TaskPriority::tryFrom($schedule->getTaskPriority()) ?? TaskPriority::Normal;

            $task = (new Task())
                ->setWorkspace($project->getWorkspace())
                ->setProject($project)
                ->setIdentifier(sprintf('%s-%d', $project->getKey(), $offset + 1))
                ->setTitle($schedule->getTaskTitle())
                ->setDescription($schedule->getTaskDescription())
                ->setStatus($status)
                ->setPriority($priority)
                ->setEstimatedMinutes($schedule->getTaskEstimatedMinutes());
            if ($schedule->getTaskAssignee() !== null) {
                $task->addAssignee($schedule->getTaskAssignee());
            }

            if (!$dryRun) {
                $this->em->persist($task);
                $schedule->setLastRunAt($now);
                $schedule->setNextRunAt($this->computeNextRun($schedule, $now));
            }
            $io->writeln(sprintf(
                '  · %s → %s/%s "%s"',
                $schedule->getName(),
                $project->getKey(),
                $task->getIdentifier(),
                $task->getTitle(),
            ));
            $created++;
        }

        if (!$dryRun && $created > 0) {
            $this->em->flush();
        }

        $io->success(sprintf('%d task(s) %s.', $created, $dryRun ? 'would be created (dry-run)' : 'created'));
        return Command::SUCCESS;
    }

    private function resolveDefaultTaskStatus(\App\Entity\Workspace $ws): ?TaskStatus
    {
        $default = $this->taskStatuses->findOneBy(['workspace' => $ws, 'isDefault' => true]);
        return $default ?? $this->taskStatuses->findOneBy(['workspace' => $ws], ['position' => 'ASC']);
    }

    private function nextTaskOffset(\App\Entity\Project $project): int
    {
        $max = 0;
        foreach ($project->getTasks() as $task) {
            if (preg_match('/-(\d+)$/', $task->getIdentifier(), $m)) {
                $n = (int) $m[1];
                if ($n > $max) {
                    $max = $n;
                }
            }
        }
        return $max;
    }

    private function computeNextRun(TaskSchedule $schedule, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $expression = new CronExpression($schedule->getCronExpression());
        $tz = new \DateTimeZone($schedule->getTimezone());
        $localNow = $now->setTimezone($tz);
        $next = $expression->getNextRunDate($localNow);
        $nextDti = \DateTimeImmutable::createFromMutable($next);
        return $nextDti->setTimezone(new \DateTimeZone('UTC'));
    }
}
