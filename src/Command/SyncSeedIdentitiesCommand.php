<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use App\Entity\ExternalIdentity;
use App\Repository\ExternalIdentityRepository;
use App\Repository\WorkspaceMemberRepository;
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
 * Auto-seeds {@see ExternalIdentity} rows for a Redmine channel by matching
 * Redmine users to local workspace members on email. This is what lets the
 * import resolve a Redmine issue's assignee (which carries only a Redmine user
 * id, no email) to a local user.
 *
 *   bin/console worktide:sync:seed-identities --channel=<uuid> [--dry-run]
 *
 * Idempotent (UNIQUE channel+externalUserId). Reports matched vs. unmatched so
 * the remaining users can be mapped by hand. Read-only against Redmine.
 */
#[AsCommand(
    name: 'worktide:sync:seed-identities',
    description: 'Map Redmine users to local users (ExternalIdentity) by email.',
)]
final class SyncSeedIdentitiesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly WorkspaceMemberRepository $members,
        private readonly ExternalIdentityRepository $identities,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Redmine sync channel UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report matches without writing');
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

        $workspace = $channel->getWorkspace();
        $dryRun = (bool) $input->getOption('dry-run');

        $matched = 0;
        $created = 0;
        $skipped = 0;
        $unmatched = [];

        foreach ($this->fetchRedmineUsers($baseUrl, $apiKey) as $u) {
            $email = $u['mail'];
            if ($email === '') {
                continue;
            }
            $member = $this->members->findByWorkspaceAndEmail($workspace, $email);
            if ($member === null) {
                $unmatched[] = sprintf('%s <%s>', $u['name'], $email);
                continue;
            }
            ++$matched;

            $existing = $this->identities->findOneBy(['channel' => $channel, 'externalUserId' => $u['id']]);
            if ($existing !== null) {
                ++$skipped;
                continue;
            }
            if (!$dryRun) {
                $this->em->persist(
                    (new ExternalIdentity())
                        ->setWorkspace($workspace)
                        ->setChannel($channel)
                        ->setUser($member->getUser())
                        ->setExternalUserId($u['id'])
                        ->setExternalEmail($email)
                        ->setExternalDisplayName($u['name']),
                );
                ++$created;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s — matched %d, created %d, already-mapped %d, unmatched %d.',
            $dryRun ? 'Dry run' : 'Identities seeded',
            $matched,
            $created,
            $skipped,
            \count($unmatched),
        ));
        if ($unmatched !== []) {
            $io->section('Unmatched Redmine users (no local member with that email)');
            $io->listing(\array_slice($unmatched, 0, 30));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{id: string, mail: string, name: string}>
     */
    private function fetchRedmineUsers(string $baseUrl, string $apiKey): array
    {
        $out = [];
        $offset = 0;
        $limit = 100;
        do {
            $resp = $this->httpClient->request('GET', $baseUrl . '/users.json', [
                'headers' => ['X-Redmine-API-Key' => $apiKey, 'Accept' => 'application/json'],
                'query' => ['limit' => $limit, 'offset' => $offset],
                'timeout' => 20,
            ]);
            if ($resp->getStatusCode() >= 400) {
                break; // non-admin token: /users.json may be forbidden
            }
            $data = $resp->toArray(false);
            foreach (($data['users'] ?? []) as $u) {
                $out[] = [
                    'id' => (string) ($u['id'] ?? ''),
                    'mail' => (string) ($u['mail'] ?? ''),
                    'name' => trim((string) ($u['firstname'] ?? '') . ' ' . (string) ($u['lastname'] ?? '')),
                ];
            }
            $total = (int) ($data['total_count'] ?? \count($out));
            $offset += $limit;
        } while ($offset < $total && \count($out) < $total);

        return $out;
    }
}
