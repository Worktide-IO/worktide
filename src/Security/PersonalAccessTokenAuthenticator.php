<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates requests bearing a Worktide Personal Access Token.
 *
 * The token is recognised on the `X-Worktide-Token: wt_pat_<hex>` header.
 *
 * We intentionally do NOT also accept `Authorization: Bearer wt_pat_<hex>`
 * — that header shape is owned by the JWT firewall, and competing on it
 * led to PATs being rejected by the JWT authenticator before this one ran.
 * Use the dedicated header for PATs; reserve Bearer for short-lived JWTs.
 *
 * `supports()` returns false for anything without our header — so the JWT
 * firewall keeps handling regular Bearer requests transparently. When this
 * guard succeeds it stamps lastUsedAt on the token for audit; failures bubble
 * as 401.
 */
final class PersonalAccessTokenAuthenticator extends AbstractAuthenticator
{
    private const PAT_PREFIX = 'wt_pat_';

    public function __construct(
        private readonly PersonalAccessTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $this->extract($request) !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $plaintext = $this->extract($request);
        if ($plaintext === null) {
            throw new CustomUserMessageAuthenticationException('Missing access token.');
        }
        $token = $this->tokens->findByPlaintext($plaintext);
        if ($token === null || !$token->isUsable(new \DateTimeImmutable())) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired access token.');
        }
        // Stamp lastUsedAt — cheap audit signal, also lets operators see
        // dormant tokens worth rotating.
        $token->setLastUsedAt(new \DateTimeImmutable());
        $this->em->flush();

        $owner = $token->getOwner();
        return new SelfValidatingPassport(
            new UserBadge($owner->getUserIdentifier(), fn () => $owner),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;  // continue into the controller
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response(
            json_encode(['error' => $exception->getMessageKey()], \JSON_UNESCAPED_SLASHES) ?: '{}',
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/json'],
        );
    }

    private function extract(Request $request): ?string
    {
        $xToken = $request->headers->get('X-Worktide-Token');
        if (\is_string($xToken) && \str_starts_with($xToken, self::PAT_PREFIX)) {
            return $xToken;
        }
        return null;
    }
}
