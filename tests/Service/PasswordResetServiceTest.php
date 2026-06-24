<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DomainEventLog;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Service\PasswordResetService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the forgot/reset-password flow.
 *
 * No database — the service is exercised over stubbed repositories +
 * collaborators, mirroring {@see \App\Tests\Security\TimeEntryBilledGuardTest}.
 * The security-critical properties are pinned here: no user enumeration,
 * only the SHA-256 hash is stored (plaintext lives solely in the mailed
 * link), and a successful reset revokes refresh tokens + logs an event.
 */
final class PasswordResetServiceTest extends TestCase
{
    private const SPA_BASE = 'https://app.example.test';
    private const MAIL_FROM = 'no-reply@example.test';

    // --- request() --------------------------------------------------

    public function testRequestForUnknownEmailIsSilent(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturn(null);

        $tokens = $this->createMock(PasswordResetTokenRepository::class);
        $tokens->expects(self::never())->method('deleteForUser');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->service($users, $tokens, $em, $mailer)->request('ghost@example.test');
    }

    public function testRequestForBlankEmailNeverTouchesRepository(): void
    {
        $users = $this->createMock(UserRepository::class);
        // A blank email must short-circuit before any lookup happens.
        $users->expects(self::never())->method('findOneBy');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->service($users, $this->createStub(PasswordResetTokenRepository::class), $this->createStub(EntityManagerInterface::class), $mailer)
            ->request('   ');
    }

    public function testRequestStoresOnlyHashAndMailsPlaintextLink(): void
    {
        $user = $this->user('alex@example.test', 'Alex');

        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturn($user);

        // Old tokens must be dropped first — only one live link per user.
        $tokens = $this->createMock(PasswordResetTokenRepository::class);
        $tokens->expects(self::once())->method('deleteForUser')->with($user);

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted = $e;
        });
        $em->expects(self::once())->method('flush');

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(function (Email $e) use (&$sent): void {
            $sent = $e;
        });

        $this->service($users, $tokens, $em, $mailer)->request('  Alex@Example.test  ');

        self::assertInstanceOf(PasswordResetToken::class, $persisted);
        self::assertInstanceOf(TemplatedEmail::class, $sent);

        // The mailed link carries the only copy of the plaintext token.
        $resetUrl = $sent->getContext()['resetUrl'] ?? '';
        self::assertIsString($resetUrl);
        self::assertStringStartsWith(self::SPA_BASE . '/reset-password?token=', $resetUrl);
        parse_str((string) parse_url($resetUrl, \PHP_URL_QUERY), $query);
        $plaintext = $query['token'] ?? '';
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $plaintext, '32 random bytes, hex-encoded');

        // What is stored is the hash of that plaintext, never the plaintext.
        self::assertSame(hash('sha256', (string) $plaintext), $persisted->getTokenHash());
        self::assertNotSame($plaintext, $persisted->getTokenHash());
        self::assertNull($persisted->getUsedAt());
        self::assertSame($user, $persisted->getUser());

        // Envelope + template wiring.
        self::assertSame('alex@example.test', $sent->getTo()[0]->getAddress());
        self::assertSame(self::MAIL_FROM, $sent->getFrom()[0]->getAddress());
        self::assertSame('email/password_reset.html.twig', $sent->getHtmlTemplate());
        self::assertSame('email/password_reset.txt.twig', $sent->getTextTemplate());
        self::assertSame('Alex', $sent->getContext()['firstName'] ?? null);
    }

    // --- consume() --------------------------------------------------

    public function testConsumeWithBlankTokenReturnsFalse(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');

        $service = $this->service(
            $this->createStub(UserRepository::class),
            $this->createStub(PasswordResetTokenRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MailerInterface::class),
            $hasher,
        );

        self::assertFalse($service->consume('   ', 'Wh4tever!Pass'));
    }

    public function testConsumeWithUnknownTokenReturnsFalse(): void
    {
        $tokens = $this->createStub(PasswordResetTokenRepository::class);
        $tokens->method('findValidByHash')->willReturn(null);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');

        $service = $this->service(
            $this->createStub(UserRepository::class),
            $tokens,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MailerInterface::class),
            $hasher,
        );

        self::assertFalse($service->consume('deadbeef', 'Wh4tever!Pass'));
    }

    public function testConsumeValidTokenResetsPasswordRevokesAndLogs(): void
    {
        $user = $this->user('alex@example.test', 'Alex');
        $token = (new PasswordResetToken())->setUser($user)->setTokenHash(hash('sha256', 'plain'));

        $tokens = $this->createStub(PasswordResetTokenRepository::class);
        $tokens->method('findValidByHash')->willReturn($token);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')->with($user, 'N3w!Password')->willReturn('HASHED');

        // The refresh-token revoke goes through a DELETE query builder.
        $query = $this->createStub(Query::class);
        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('delete')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('orWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $logged = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($qb);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$logged): void {
            $logged = $e;
        });
        $em->expects(self::once())->method('flush');

        $service = $this->service(
            $this->createStub(UserRepository::class),
            $tokens,
            $em,
            $this->createStub(MailerInterface::class),
            $hasher,
        );

        self::assertTrue($service->consume('plain', 'N3w!Password'));
        self::assertSame('HASHED', $user->getPassword());
        self::assertNotNull($token->getUsedAt(), 'token must be marked used');

        self::assertInstanceOf(DomainEventLog::class, $logged);
        self::assertSame('auth.password.reset', $logged->getName());
    }

    // --- helpers ----------------------------------------------------

    private function service(
        UserRepository $users,
        PasswordResetTokenRepository $tokens,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        ?UserPasswordHasherInterface $hasher = null,
    ): PasswordResetService {
        return new PasswordResetService(
            $em,
            $users,
            $tokens,
            $hasher ?? $this->createStub(UserPasswordHasherInterface::class),
            $mailer,
            new RequestStack(),
            self::SPA_BASE,
            self::MAIL_FROM,
        );
    }

    private function user(string $email, string $firstName): User
    {
        $u = (new User())->setEmail($email)->setFirstName($firstName);
        $ref = new \ReflectionProperty($u, 'id');
        $ref->setValue($u, Uuid::v7());

        return $u;
    }
}
