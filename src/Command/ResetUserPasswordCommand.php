<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Dev-only helper: resets a user's password by email. Useful after the awork
 * import which gives every imported user a random un-loginable password.
 */
#[AsCommand(name: 'app:user:reset-password', description: 'Reset a user password (dev only).')]
final class ResetUserPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user === null) {
            $output->writeln("<error>No user with email $email</error>");
            return Command::FAILURE;
        }
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->flush();
        $output->writeln("<info>ok: $email → password updated</info>");
        return Command::SUCCESS;
    }
}
