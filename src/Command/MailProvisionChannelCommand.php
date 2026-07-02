<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\Adapter\Email\EmailImapAdapter;
use App\Entity\Channel;
use App\Entity\Enum\ChannelCapability;
use App\Entity\User;
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
 * Idempotently create/update an IMAP e-mail {@see Channel} from the CLI.
 * Persists via Doctrine, so authConfig (username/password) is encrypted at rest
 * by ChannelAuthConfigCipherListener.
 *
 *   bin/console worktide:mail:provision \
 *     --workspace=<uuid> --name="Support Inbox" \
 *     --host=imap.example.com --username=support@example.com \
 *     --password-file=~/.config/support-imap [--backfill-months=6]
 *
 * The password is read from a file (never on the command line / in logs).
 * A bounded backfill window (default 6 months) is stored in inboundConfig so the
 * first `channel:pull --backfill` ingests only recent history, not the whole
 * 10GB+ mailbox; incremental pulls continue forward via the UID cursor.
 * Re-running with the same (workspace, name) updates config in place.
 */
#[AsCommand(
    name: 'worktide:mail:provision',
    description: 'Create/update an IMAP e-mail channel (encrypted credentials, bounded backfill).',
)]
final class MailProvisionChannelCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace UUID')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Channel display name (unique per workspace)')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'IMAP host, e.g. imap.example.com')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'IMAP port', '993')
            ->addOption('encryption', null, InputOption::VALUE_REQUIRED, "IMAP encryption: ssl | tls | '' (none)", 'ssl')
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'IMAP folder', 'INBOX')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'IMAP username / mailbox login')
            ->addOption('password-file', null, InputOption::VALUE_REQUIRED, 'Path to a file containing the IMAP password')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'IMAP password inline (prefer --password-file)')
            ->addOption('backfill-months', null, InputOption::VALUE_REQUIRED, 'Backfill window in months (larger = more history + storage)', '6')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Personal mailbox owner (user UUID or email). Omit = shared team mailbox.')
            // Optional SMTP for outbound replies (defaults reuse the IMAP login).
            ->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP host (omit = no outbound)')
            ->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP port', '465')
            ->addOption('smtp-encryption', null, InputOption::VALUE_REQUIRED, 'SMTP encryption: ssl | tls', 'ssl')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'From address for outbound (default = username)')
            ->addOption('from-name', null, InputOption::VALUE_REQUIRED, 'From display name for outbound');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = (string) $input->getOption('name');
        $host = (string) $input->getOption('host');
        $username = (string) $input->getOption('username');
        if ($name === '' || $host === '' || $username === '') {
            $io->error('--name, --host and --username are required.');
            return Command::INVALID;
        }

        $workspace = $this->resolveWorkspace($input->getOption('workspace'), $io);
        if ($workspace === null) {
            return Command::INVALID;
        }

        $password = $this->readSecret($input, $io);
        if ($password === null) {
            return Command::INVALID;
        }

        $owner = null;
        $ownerOpt = $input->getOption('owner');
        if (\is_string($ownerOpt) && $ownerOpt !== '') {
            $owner = $this->resolveUser($ownerOpt, $io);
            if ($owner === null) {
                return Command::INVALID;
            }
        }

        $months = max(1, (int) $input->getOption('backfill-months'));
        $backfillSince = (new \DateTimeImmutable(sprintf('-%d months', $months)))->format('Y-m-d');

        $inbound = [
            'host' => $host,
            'port' => (int) $input->getOption('port'),
            'encryption' => (string) $input->getOption('encryption'),
            'folder' => (string) $input->getOption('folder'),
            'backfillSince' => $backfillSince,
            'cursor' => 0,
        ];

        $auth = ['username' => $username, 'password' => $password];

        $outbound = [];
        $smtpHost = $input->getOption('smtp-host');
        if (\is_string($smtpHost) && $smtpHost !== '') {
            $outbound = [
                'host' => $smtpHost,
                'port' => (int) $input->getOption('smtp-port'),
                'encryption' => (string) $input->getOption('smtp-encryption'),
                'from' => (string) ($input->getOption('from') ?? $username) ?: $username,
                'fromName' => (string) ($input->getOption('from-name') ?? ''),
            ];
        }

        $channel = $this->em->getRepository(Channel::class)->findOneBy(['workspace' => $workspace, 'name' => $name])
            ?? (new Channel())->setWorkspace($workspace)->setName($name);

        // Preserve an existing cursor on re-provision so we don't re-backfill.
        $existingCfg = $channel->getInboundConfig();
        if (isset($existingCfg['cursor']) && (int) $existingCfg['cursor'] > 0) {
            $inbound['cursor'] = (int) $existingCfg['cursor'];
        }

        $capabilities = $outbound === []
            ? [ChannelCapability::Inbound]
            : [ChannelCapability::Inbound, ChannelCapability::Outbound];

        $channel
            ->setAdapterCode(EmailImapAdapter::CODE)
            ->setCapabilities($capabilities)
            ->setEntityTypes(['conversation'])
            ->setInboundConfig($inbound)
            ->setOutboundConfig($outbound)
            ->setAuthConfig($auth) // encrypted on flush by the cipher listener
            ->setIsShared($owner === null)
            ->setOwnerUser($owner)
            ->setIsEnabled(true);

        $this->em->persist($channel);
        $this->em->flush();

        $io->success(sprintf(
            'Mail channel "%s" provisioned — %s, backfill since %s.',
            $name,
            $owner !== null ? 'personal (owner ' . ($owner->getEmail() ?? $owner->getId()?->toRfc4122()) . ')' : 'shared',
            $backfillSince,
        ));
        $id = $channel->getId()?->toRfc4122();
        $io->writeln('Channel UUID: <info>' . $id . '</info>');
        $io->writeln('Backfill:   <info>bin/console worktide:channel:pull --backfill --throttle-ms=1000 --channel=' . $id . '</info>');
        $io->writeln('Incremental: schedule <info>bin/console worktide:channel:pull --channel=' . $id . '</info> via cron');

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

    private function resolveUser(string $ref, SymfonyStyle $io): ?User
    {
        try {
            $user = $this->em->find(User::class, Uuid::fromString($ref));
            if ($user !== null) {
                return $user;
            }
        } catch (\InvalidArgumentException) {
            // Not a UUID — fall through to email lookup.
        }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $ref]);
        if ($user === null) {
            $io->error('--owner user not found (by UUID or email): ' . $ref);
        }

        return $user;
    }

    private function readSecret(InputInterface $input, SymfonyStyle $io): ?string
    {
        $file = $input->getOption('password-file');
        if (\is_string($file) && $file !== '') {
            $path = str_starts_with($file, '~') ? (getenv('HOME') . substr($file, 1)) : $file;
            if (!is_readable($path)) {
                $io->error('--password-file is not readable: ' . $path);
                return null;
            }
            $secret = trim((string) file_get_contents($path));
            if ($secret === '') {
                $io->error('--password-file is empty.');
                return null;
            }
            return $secret;
        }
        $inline = $input->getOption('password');
        if (\is_string($inline) && $inline !== '') {
            return $inline;
        }
        $io->error('Provide --password-file (preferred) or --password.');

        return null;
    }
}
