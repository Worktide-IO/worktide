<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use App\Entity\Project;
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
 * Generates a reviewable project-mapping file for a Redmine sync channel:
 * Redmine project → local worktide project. Confident name matches (against the
 * channel's workspace projects) are pre-filled; everything else defaults to
 * `"new"` (create a project on import). The operator edits the file, then
 * `worktide:sync:seed-import --map=<file>` routes issues per project.
 *
 *   bin/console worktide:sync:map-projects --channel=<uuid> [--out=var/redmine-project-map.json]
 *
 * Read-only against Redmine (inbound). Matching is intentionally conservative —
 * only a UNIQUE normalized-name hit is auto-assigned, to avoid false pairings.
 */
#[AsCommand(
    name: 'worktide:sync:map-projects',
    description: 'Generate a reviewable Redmine→worktide project mapping file.',
)]
final class SyncMapProjectsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Redmine sync channel UUID')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output JSON path', 'var/redmine-project-map.json');
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
        if (!$channel instanceof Channel) {
            $io->error('Channel not found.');
            return Command::INVALID;
        }
        if ($channel->getAdapterCode() !== 'redmine') {
            $io->error('This command currently supports redmine channels only.');
            return Command::INVALID;
        }

        $baseUrl = rtrim((string) ($channel->getInboundConfig()['baseUrl'] ?? ''), '/');
        $apiKey = (string) ($channel->getAuthConfig()['apiKey'] ?? '');
        if ($baseUrl === '' || $apiKey === '') {
            $io->error('Channel is missing baseUrl or apiKey.');
            return Command::FAILURE;
        }

        // 1. Redmine projects (paginated, read-only)
        $remote = $this->fetchRedmineProjects($baseUrl, $apiKey);
        $io->writeln(sprintf('Redmine projects: %d', \count($remote)));

        // 2. Local projects in the channel's workspace
        $local = $this->em->getRepository(Project::class)->findBy(['workspace' => $channel->getWorkspace()]);
        /** @var array<string, array{id: string, name: string}> $localByNorm */
        $localByNorm = [];
        foreach ($local as $p) {
            $localByNorm[$this->norm($p->getName())] = ['id' => $p->getId()?->toRfc4122() ?? '', 'name' => $p->getName()];
        }
        $io->writeln(sprintf('Local projects in workspace: %d', \count($local)));

        // 3. Build mapping with conservative auto-match
        $map = [];
        $autoMatched = 0;
        foreach ($remote as $rp) {
            $match = $this->confidentMatch($rp['name'], $localByNorm);
            if ($match !== null) {
                ++$autoMatched;
                $map[] = [
                    'redmineProjectId' => $rp['id'],
                    'redmineIdentifier' => $rp['identifier'],
                    'redmineName' => $rp['name'],
                    'target' => $match['id'],          // existing worktide project UUID
                    'targetName' => $match['name'],
                    'match' => 'auto-name',
                ];
            } else {
                $map[] = [
                    'redmineProjectId' => $rp['id'],
                    'redmineIdentifier' => $rp['identifier'],
                    'redmineName' => $rp['name'],
                    'target' => 'new',                  // edit to a UUID or "skip"
                    'targetName' => null,
                    'match' => null,
                ];
            }
        }

        $outPath = (string) $input->getOption('out');
        $absolute = str_starts_with($outPath, '/') ? $outPath : getcwd() . '/' . $outPath;
        @mkdir(\dirname($absolute), 0o775, true);
        file_put_contents($absolute, json_encode($map, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) . "\n");

        $io->success(sprintf(
            'Wrote %d mappings to %s — %d auto-matched, %d default to "new".',
            \count($map),
            $outPath,
            $autoMatched,
            \count($map) - $autoMatched,
        ));
        $io->writeln('Review/edit the file: set "target" to an existing project UUID, "new", or "skip". Then run:');
        $io->writeln(sprintf('  bin/console worktide:sync:seed-import --channel=%s --map=%s', $channel->getId()?->toRfc4122(), $outPath));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id: string, name: string, identifier: string}>
     */
    private function fetchRedmineProjects(string $baseUrl, string $apiKey): array
    {
        $out = [];
        $offset = 0;
        $limit = 100;
        do {
            $resp = $this->httpClient->request('GET', $baseUrl . '/projects.json', [
                'headers' => ['X-Redmine-API-Key' => $apiKey, 'Accept' => 'application/json'],
                'query' => ['limit' => $limit, 'offset' => $offset],
                'timeout' => 20,
            ]);
            $data = $resp->toArray(false);
            foreach (($data['projects'] ?? []) as $p) {
                $out[] = [
                    'id' => (string) ($p['id'] ?? ''),
                    'name' => (string) ($p['name'] ?? ''),
                    'identifier' => (string) ($p['identifier'] ?? ''),
                ];
            }
            $total = (int) ($data['total_count'] ?? \count($out));
            $offset += $limit;
        } while ($offset < $total && \count($out) < $total);

        return $out;
    }

    /**
     * Unique, confident normalized-name match, or null.
     *
     * @param array<string, array{id: string, name: string}> $localByNorm
     * @return array{id: string, name: string}|null
     */
    private function confidentMatch(string $remoteName, array $localByNorm): ?array
    {
        $nr = $this->norm($remoteName);
        if ($nr === '') {
            return null;
        }
        if (isset($localByNorm[$nr])) {
            return $localByNorm[$nr];
        }
        $candidates = [];
        foreach ($localByNorm as $nl => $info) {
            // substring either way, but only when both sides are long enough to be meaningful
            if (\strlen($nr) >= 5 && \strlen($nl) >= 5 && (str_contains($nr, $nl) || str_contains($nl, $nr))) {
                $candidates[] = $info;
            }
        }

        return \count($candidates) === 1 ? $candidates[0] : null;
    }

    private function norm(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
    }
}
