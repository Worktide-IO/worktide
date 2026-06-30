<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Repository\EntitySyncRepository;
use App\Repository\ProjectStatusRepository;
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
 * Reconciles imported projects' status with their *live* Redmine project
 * status — fixing the bulk default left by the import. Redmine project status
 * maps to a worktide {@see ProjectStatus}:
 *   - 1 (open/active) → a running status ("Läuft")
 *   - 5 (closed)      → the workspace's completed status ("Abgeschlossen")
 *   - archived (9) projects are not returned by /projects.json → left as-is.
 *
 *   bin/console worktide:sync:reapply-project-statuses --channel=<uuid> [--dry-run]
 *
 * Inbound-only (reads Redmine, writes locally inside the SyncReentryGuard so no
 * outbound push is queued).
 */
#[AsCommand(
    name: 'worktide:sync:reapply-project-statuses',
    description: 'Set imported project statuses from their live Redmine project status.',
)]
final class SyncReapplyProjectStatusesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly EntitySyncRepository $syncs,
        private readonly ProjectStatusRepository $projectStatuses,
        private readonly SyncReentryGuard $guard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Redmine sync channel UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report changes without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $channelId = (string) $input->getOption('channel');
        try {
            $channel = $channelId !== '' ? $this->em->find(Channel::class, Uuid::fromString($channelId)) : null;
        } catch (\InvalidArgumentException) {
            $io->error('--channel is not a valid UUID.');
            return Command::INVALID;
        }
        if (!$channel instanceof Channel || $channel->getAdapterCode() !== 'redmine') {
            $io->error('A redmine channel UUID is required.');
            return Command::INVALID;
        }

        $baseUrl = rtrim((string) ($channel->getInboundConfig()['baseUrl'] ?? ''), '/');
        $apiKey = (string) ($channel->getAuthConfig()['apiKey'] ?? '');
        if ($baseUrl === '' || $apiKey === '') {
            $io->error('Channel missing baseUrl or apiKey.');
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        // Target statuses in this workspace.
        $statuses = $this->projectStatuses->findBy(['workspace' => $channel->getWorkspace()], ['position' => 'ASC']);
        if ($statuses === []) {
            $io->error('Workspace has no ProjectStatus.');
            return Command::FAILURE;
        }
        $running = $this->pickRunning($statuses);
        $completed = $this->pickCompleted($statuses) ?? $running;

        // Live Redmine project statuses: { redmineProjectId => statusInt }.
        try {
            $redmineStatus = $this->fetchRedmineProjectStatuses($baseUrl, $apiKey);
        } catch (\Throwable $e) {
            $io->error('Could not read Redmine projects: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->writeln(sprintf('<info>%d Redmine projects read.</info>', \count($redmineStatus)));

        $changed = 0;
        $unchanged = 0;
        $skipped = 0;
        $openCount = 0;
        $closedCount = 0;

        $apply = function () use ($channel, $redmineStatus, $running, $completed, $dryRun, &$changed, &$unchanged, &$skipped, &$openCount, &$closedCount): void {
            $mappings = $this->syncs->findBy(['channel' => $channel, 'entityType' => 'project']);
            foreach ($mappings as $mapping) {
                $rid = $mapping->getExternalId();
                if (!\array_key_exists($rid, $redmineStatus)) {
                    ++$skipped; // not returned by Redmine (e.g. archived) — leave as-is
                    continue;
                }
                $isClosed = $redmineStatus[$rid] === 5;
                $isClosed ? ++$closedCount : ++$openCount;
                $target = $isClosed ? $completed : $running;

                $project = $this->em->find(Project::class, $mapping->getEntityId());
                if (!$project instanceof Project) {
                    ++$skipped;
                    continue;
                }
                if ($project->getStatus() === $target) {
                    ++$unchanged;
                    continue;
                }
                if (!$dryRun) {
                    $project->setStatus($target);
                }
                ++$changed;
            }
            if (!$dryRun) {
                $this->em->flush();
            }
        };

        $dryRun ? $apply() : $this->guard->run($apply);

        $io->success(sprintf(
            '%s — open→%s: %d, closed→%s: %d | changed %d, unchanged %d, skipped %d.',
            $dryRun ? 'Dry run' : 'Applied',
            $running->getName(),
            $openCount,
            $completed->getName(),
            $closedCount,
            $changed,
            $unchanged,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int> redmineProjectId (string) => status int
     */
    private function fetchRedmineProjectStatuses(string $baseUrl, string $apiKey): array
    {
        $out = [];
        $offset = 0;
        $limit = 100;
        do {
            $resp = $this->httpClient->request('GET', $baseUrl . '/projects.json', [
                'headers' => ['X-Redmine-API-Key' => $apiKey, 'Accept' => 'application/json'],
                'query' => ['limit' => $limit, 'offset' => $offset],
                'timeout' => 30,
            ]);
            if ($resp->getStatusCode() >= 400) {
                throw new \RuntimeException('HTTP ' . $resp->getStatusCode());
            }
            $data = $resp->toArray(false);
            $projects = $data['projects'] ?? [];
            foreach ($projects as $p) {
                if (isset($p['id'])) {
                    $out[(string) $p['id']] = (int) ($p['status'] ?? 1);
                }
            }
            $total = (int) ($data['total_count'] ?? \count($out));
            $offset += $limit;
        } while ($offset < $total && $projects !== []);

        return $out;
    }

    /**
     * @param list<ProjectStatus> $statuses
     */
    private function pickRunning(array $statuses): ProjectStatus
    {
        foreach ($statuses as $s) {
            if (preg_match('/läuft|laufend|aktiv|active|running|in bearbeitung|in progress|offen/i', $s->getName())) {
                return $s;
            }
        }
        foreach ($statuses as $s) {
            if (!$s->isCompleted() && !$s->isArchived()) {
                return $s;
            }
        }

        return $statuses[0];
    }

    /**
     * @param list<ProjectStatus> $statuses
     */
    private function pickCompleted(array $statuses): ?ProjectStatus
    {
        foreach ($statuses as $s) {
            if (preg_match('/abgeschlossen|completed|fertig|erledigt|geschlossen|closed/i', $s->getName())) {
                return $s;
            }
        }
        foreach ($statuses as $s) {
            if ($s->isCompleted()) {
                return $s;
            }
        }

        return null;
    }
}
