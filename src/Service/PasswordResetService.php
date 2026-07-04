<?php

declare(strict_types=1);

namespace App\Service;

use App\Egress\EgressGuard;
use App\Egress\EgressModule;
use App\Entity\DomainEventLog;
use App\Entity\PasswordResetToken;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Forgot/reset-password orchestration.
 *
 * Security properties:
 *  - No user enumeration: {@see request()} is silent for unknown emails.
 *  - Tokens are single-use, short-lived, and stored only as a SHA-256 hash
 *    (plaintext lives only in the emailed link).
 *  - One live token per user — issuing a new one drops the old ones.
 *  - A successful reset revokes the user's refresh tokens, so any open
 *    session must re-authenticate (the stateless JWT still lives until its
 *    own TTL — acceptable, bounded window).
 */
final class PasswordResetService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly PasswordResetTokenRepository $tokens,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly MailerInterface $mailer,
        private readonly RequestStack $requestStack,
        private readonly EgressGuard $egress,
        private readonly string $spaBaseUrl,
        private readonly string $portalBaseUrl,
        private readonly string $mailFrom,
    ) {}

    /**
     * Issue a reset link for the given email — if (and only if) an account
     * exists. Callers must NOT branch on whether a mail was sent.
     */
    public function request(string $email): void
    {
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '') {
            return;
        }
        $user = $this->users->findOneBy(['email' => $normalized]);
        if (!$user instanceof User) {
            return; // silent — no enumeration
        }

        // Only ever one live token per user.
        $this->tokens->deleteForUser($user);

        $plaintext = bin2hex(random_bytes(32));
        $token = (new PasswordResetToken())
            ->setUser($user)
            ->setTokenHash(hash('sha256', $plaintext));
        $this->em->persist($token);
        $this->em->flush();

        $resetUrl = rtrim($this->spaBaseUrl, '/') . '/reset-password?token=' . $plaintext;

        $mail = (new TemplatedEmail())
            ->from($this->mailFrom)
            ->to($user->getEmail())
            ->subject('Passwort zurücksetzen')
            ->htmlTemplate('email/password_reset.html.twig')
            ->textTemplate('email/password_reset.txt.twig')
            ->context([
                'resetUrl' => $resetUrl,
                'firstName' => $user->getFirstName(),
                'expiresAt' => $token->getExpiresAt(),
            ]);
        // Routed async via Messenger (SendEmailMessage: async).
        // Default-deny egress gate: the reset token is always issued, but the
        // email only leaves the system when email_outbound is approved (EGRESS_ALLOW).
        if (!$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return;
        }
        $this->mailer->send($mail);
    }

    /**
     * Issue a "set your password" link for a freshly-provisioned portal user
     * and mail it. Unlike {@see request()} this is NOT user-triggered and NOT
     * enumeration-sensitive — it is called by the staff grant-portal-access
     * action for a User we just created, so it takes the User directly and the
     * link points at the customer portal (not the staff SPA).
     *
     * Reuses the same single-use token machinery; the portal's
     * `/set-password?token=` page posts back to the shared
     * `POST /v1/auth/reset-password`.
     */
    public function sendPortalSetPasswordLink(User $user): void
    {
        // Only ever one live token per user.
        $this->tokens->deleteForUser($user);

        $plaintext = bin2hex(random_bytes(32));
        $token = (new PasswordResetToken())
            ->setUser($user)
            ->setTokenHash(hash('sha256', $plaintext));
        $this->em->persist($token);
        $this->em->flush();

        $setPasswordUrl = rtrim($this->portalBaseUrl, '/') . '/set-password?token=' . $plaintext;

        $mail = (new TemplatedEmail())
            ->from($this->mailFrom)
            ->to($user->getEmail())
            ->subject('Ihr Zugang zum Kundenportal')
            ->htmlTemplate('email/portal_set_password.html.twig')
            ->textTemplate('email/portal_set_password.txt.twig')
            ->context([
                'setPasswordUrl' => $setPasswordUrl,
                'firstName' => $user->getFirstName(),
                'expiresAt' => $token->getExpiresAt(),
            ]);

        if (!$this->egress->isAllowed(EgressModule::EmailOutbound)) {
            return;
        }
        $this->mailer->send($mail);
    }

    /**
     * Consume a reset token and set the new (already policy-checked) password.
     *
     * @return bool true on success, false if the token is unknown/expired/used
     */
    public function consume(string $token, string $newPassword): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $entity = $this->tokens->findValidByHash(hash('sha256', $token), new \DateTimeImmutable());
        if (!$entity instanceof PasswordResetToken) {
            return false;
        }

        $user = $entity->getUser();
        $user->setPassword($this->hasher->hashPassword($user, $newPassword));
        $entity->markUsed();

        $this->revokeRefreshTokens($user);

        $req = $this->requestStack->getMainRequest();
        $this->em->persist(new DomainEventLog(
            name: 'auth.password.reset',
            aggregateType: 'Auth',
            aggregateId: $user->getId(),
            workspace: null,
            actor: $user,
            payload: [
                'ip' => $req?->getClientIp() ?? 'unknown',
                'userAgent' => mb_substr($req?->headers->get('User-Agent', '—') ?? '—', 0, 255),
            ],
        ));
        $this->em->flush();

        return true;
    }

    /**
     * Drop all refresh tokens for the user (by user-id metadata or, for
     * legacy rows, by username/email) so existing sessions can't silently
     * mint fresh JWTs after a reset.
     */
    private function revokeRefreshTokens(User $user): void
    {
        $this->em->createQueryBuilder()
            ->delete(RefreshToken::class, 'r')
            ->where('r.userId = :uid')
            ->orWhere('r.username = :email')
            ->setParameter('uid', $user->getId())
            ->setParameter('email', $user->getEmail())
            ->getQuery()
            ->execute();
    }
}
