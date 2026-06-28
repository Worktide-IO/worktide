<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use App\Entity\TaskStatus;
use App\Repository\TaskStatusRepository;
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
 * Builds the Redmine-status → worktide-TaskStatus map so imported tasks don't
 * all collapse to the default status. Stored channel-scoped in
 * `inboundConfig['statusMap']` ({redmineStatusId: taskStatusUuid}) and consumed
 * by {@see \App\Service\Inbound\TaskEnricher}.
 *
 *   bin/console worktide:sync:map-statuses --channel=<uuid> [--dry-run]
 *
 * Rule per Redmine status: exact name match (case-insensitive) › if the Redmine
 * status is closed, the first completed worktide status › the default status.
 * Re-run any time; edit inboundConfig.statusMap by hand to override.
 */
#[AsCommand(
    name: 'worktide:sync:map-statuses',
    description: 'Map Redmine issue statuses to worktide task statuses (channel inboundConfig).',
)]
final class SyncMapStatusesCommand extends Command
{
    /**
     * Semantic pre-mapping for Redmine statuses whose name doesn't match a
     * worktide status 1:1. Keys are normalized Redmine names, values are
     * worktide status names. Edit channel.inboundConfig.statusMap to override.
     */
    private const SYNONYMS = [
        'zur entwicklung ausgewählt' => 'To Do',
        'rückfrage beantwortet' => 'To Do',
        'fehler' => 'To Do',
        'kostenvoranschlag' => 'Klärung notwendig',
        'besprechung' => 'Klärung notwendig',
        'enthält rückfrage' => 'Klärung notwendig',
        'in anfrage' => 'Klärung notwendig',
        'abnahme' => 'In Überprüfung',
        'blocker' => 'Blockiert',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly TaskStatusRepository $taskStatuses,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Redmine sync channel UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the mapping without saving');
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

        // Local task statuses for this workspace.
        /** @var list<TaskStatus> $statuses */
        $statuses = $this->taskStatuses->findBy(['workspace' => $channel->getWorkspace()]);
        if ($statuses === []) {
            $io->error('Workspace has no task statuses.');
            return Command::FAILURE;
        }
        $byName = [];
        foreach ($statuses as $s) {
            $byName[mb_strtolower($s->getName())] = $s;
        }
        $default = $this->pick($statuses, static fn (TaskStatus $s) => $s->isDefault()) ?? $statuses[0];
        $completed = $this->pick($statuses, static fn (TaskStatus $s) => $s->isCompleted()) ?? $default;

        // Redmine statuses (catalog carries is_closed).
        $resp = $this->httpClient->request('GET', $baseUrl . '/issue_statuses.json', [
            'headers' => ['X-Redmine-API-Key' => $apiKey, 'Accept' => 'application/json'],
            'timeout' => 20,
        ]);
        if ($resp->getStatusCode() >= 400) {
            $io->error('Could not read /issue_statuses.json (HTTP ' . $resp->getStatusCode() . ').');
            return Command::FAILURE;
        }

        $map = [];
        $rows = [];
        foreach (($resp->toArray(false)['issue_statuses'] ?? []) as $rs) {
            $id = (string) ($rs['id'] ?? '');
            $name = (string) ($rs['name'] ?? '');
            $closed = (bool) ($rs['is_closed'] ?? false);
            if ($id === '') {
                continue;
            }
            $lc = mb_strtolower($name);
            $synonym = isset(self::SYNONYMS[$lc]) ? ($byName[mb_strtolower(self::SYNONYMS[$lc])] ?? null) : null;
            if (isset($byName[$lc])) {
                $target = $byName[$lc];
                $rule = 'name';
            } elseif ($synonym !== null) {
                $target = $synonym;
                $rule = 'synonym';
            } elseif ($closed) {
                $target = $completed;
                $rule = 'closed→completed';
            } else {
                $target = $default;
                $rule = 'default';
            }
            $map[$id] = $target->getId()?->toRfc4122();
            $rows[] = [$name . ($closed ? ' (closed)' : ''), $target->getName(), $rule];
        }

        $io->table(['Redmine status', '→ worktide status', 'rule'], $rows);

        if ($input->getOption('dry-run')) {
            $io->note('Dry run — not saved.');
            return Command::SUCCESS;
        }

        $cfg = $channel->getInboundConfig();
        $cfg['statusMap'] = $map;
        $channel->setInboundConfig($cfg);
        $this->em->flush();

        $io->success(sprintf('Saved status map (%d statuses) to channel inboundConfig.', \count($map)));

        return Command::SUCCESS;
    }

    /**
     * @param list<TaskStatus> $statuses
     * @param callable(TaskStatus): bool $pred
     */
    private function pick(array $statuses, callable $pred): ?TaskStatus
    {
        foreach ($statuses as $s) {
            if ($pred($s)) {
                return $s;
            }
        }

        return null;
    }
}
