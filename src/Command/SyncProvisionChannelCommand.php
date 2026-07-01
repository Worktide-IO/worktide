<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Idempotently create/update a ticket-sync {@see Channel} (redmine/jira) from
 * the CLI, so the live-data seed has somewhere to pull from. Persists via
 * Doctrine, so authConfig is encrypted at rest by ChannelAuthConfigCipherListener.
 *
 *   bin/console worktide:sync:provision-channel \
 *     --adapter=redmine --workspace=<uuid> --name="Redmine" \
 *     --base-url=https://redmine.example.com \
 *     --api-key-file=~/.config/redmine-token [--project-id=48]
 *
 * The secret is read from a file (never passed on the command line / logged).
 * Re-running with the same (workspace, name) updates config in place and prints
 * the channel UUID. Capabilities are [inbound, outbound] and entityTypes=[task];
 * outbound push still stays withheld by the EgressGuard until ticket_push is
 * approved, so provisioning never causes egress.
 */
#[AsCommand(
    name: 'worktide:sync:provision-channel',
    description: 'Create/update a Redmine or Jira sync channel (encrypted credentials).',
)]
final class SyncProvisionChannelCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('adapter', null, InputOption::VALUE_REQUIRED, 'redmine | jira')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace UUID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Channel display name (unique per workspace)')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'e.g. https://redmine.example.com')
            ->addOption('api-key-file', null, InputOption::VALUE_REQUIRED, 'Path to a file containing the API key / PAT')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key / PAT inline (prefer --api-key-file)')
            ->addOption('project-id', null, InputOption::VALUE_REQUIRED, 'Redmine numeric projectId / Jira projectKey (omit = all)')
            ->addOption('jira-email', null, InputOption::VALUE_REQUIRED, 'Jira Cloud account email (enables Basic auth)')
            ->addOption('api-version', null, InputOption::VALUE_REQUIRED, 'Jira REST API version (2=Server/DC default, 3=Cloud)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $adapter = (string) $input->getOption('adapter');
        if (!\in_array($adapter, ['redmine', 'jira'], true)) {
            $io->error('--adapter must be "redmine" or "jira".');
            return Command::INVALID;
        }
        $baseUrl = rtrim((string) $input->getOption('base-url'), '/');
        $name = (string) $input->getOption('name');
        if ($baseUrl === '' || $name === '') {
            $io->error('--base-url and --name are required.');
            return Command::INVALID;
        }

        $workspace = $this->resolveWorkspace($input->getOption('workspace'), $io);
        if ($workspace === null) {
            return Command::INVALID;
        }

        $apiKey = $this->readSecret($input, $io);
        if ($apiKey === null) {
            return Command::INVALID;
        }

        $projectId = $input->getOption('project-id');
        $inbound = ['baseUrl' => $baseUrl];
        if ($adapter === 'jira') {
            $inbound['apiVersion'] = (string) ($input->getOption('api-version') ?? '2');
            if ($projectId !== null) {
                $inbound['projectKey'] = (string) $projectId;
            }
            $jiraEmail = $input->getOption('jira-email');
            $auth = $jiraEmail !== null
                ? ['email' => (string) $jiraEmail, 'apiToken' => $apiKey]
                : ['personalAccessToken' => $apiKey];
        } else {
            if ($projectId !== null) {
                $inbound['projectId'] = (string) $projectId;
            }
            $auth = ['apiKey' => $apiKey];
        }

        $channel = $this->em->getRepository(Channel::class)->findOneBy(['workspace' => $workspace, 'name' => $name])
            ?? (new Channel())->setWorkspace($workspace)->setName($name);

        $channel
            ->setAdapterCode($adapter)
            ->setCapabilities([ChannelCapability::Inbound, ChannelCapability::Outbound])
            ->setEntityTypes(['task'])
            ->setInboundConfig($inbound)
            ->setOutboundConfig(['baseUrl' => $baseUrl])
            ->setAuthConfig($auth) // encrypted on flush by the cipher listener
            ->setIsEnabled(true);

        $this->em->persist($channel);
        $this->em->flush();

        $io->success(sprintf('Channel "%s" (%s) provisioned.', $name, $adapter));
        $io->writeln('Channel UUID: <info>' . $channel->getId()?->toRfc4122() . '</info>');
        $io->writeln('Next: bin/console worktide:sync:seed-import --channel=' . $channel->getId()?->toRfc4122() . ' --project=<projectUuid> --limit=25');

        return Command::SUCCESS;
    }

    private function resolveWorkspace(mixed $id, SymfonyStyle $io): ?Workspace
    {
        if (!\is_string($id) || $id === '') {
            $io->error('--workspace=<uuid> is required.');
            return null;
        }
        try {
            $ws = $this->em->find(Workspace::class, Uuid::fromString($id));
        } catch (\InvalidArgumentException) {
            $io->error('--workspace is not a valid UUID.');
            return null;
        }
        if ($ws === null) {
            $io->error('Workspace not found.');
        }

        return $ws;
    }

    private function readSecret(InputInterface $input, SymfonyStyle $io): ?string
    {
        $file = $input->getOption('api-key-file');
        if (\is_string($file) && $file !== '') {
            $path = str_starts_with($file, '~') ? (getenv('HOME') . substr($file, 1)) : $file;
            if (!is_readable($path)) {
                $io->error('--api-key-file is not readable: ' . $path);
                return null;
            }
            $secret = trim((string) file_get_contents($path));
            if ($secret === '') {
                $io->error('--api-key-file is empty.');
                return null;
            }
            return $secret;
        }
        $inline = $input->getOption('api-key');
        if (\is_string($inline) && $inline !== '') {
            return $inline;
        }
        $io->error('Provide --api-key-file (preferred) or --api-key.');

        return null;
    }
}
