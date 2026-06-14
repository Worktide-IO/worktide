<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PersonalAccessToken;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Generates the token plaintext on POST. Stores only its SHA-256 hash in the
 * DB, but stamps the plaintext onto the entity in-memory so the standard
 * serializer can ship it back to the client in the create response.
 *
 * The plaintext is returned EXACTLY ONCE — subsequent GETs only see the
 * prefix. The Owner is force-set to the authenticated user (clients can't
 * mint tokens for somebody else).
 *
 * On PATCH, supplying `isRevoked: true` (via the `revoke` mutator below) sets
 * revokedAt; nothing else about the token can be changed after creation.
 */
#[AsDecorator('api_platform.doctrine.orm.state.persist_processor', priority: 20)]
final class PersonalAccessTokenIssueProcessor implements ProcessorInterface
{
    private const TOKEN_PREFIX_HUMAN = 'wt_pat_';

    public function __construct(
        private readonly ProcessorInterface $inner,
        private readonly Security $security,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof PersonalAccessToken && $operation instanceof Post) {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new AccessDeniedHttpException('Auth required to mint a token.');
            }
            $plaintext = self::TOKEN_PREFIX_HUMAN . bin2hex(random_bytes(24));
            $data->setOwner($user);
            $data->setTokenHash(hash('sha256', $plaintext));
            $data->setTokenPrefix(\substr($plaintext, 0, 12));

            // Stash the plaintext on a non-persisted property so the response
            // includes it. The next GET won't return it — only POST does.
            $data->setPlaintextToken($plaintext);
        }
        return $this->inner->process($data, $operation, $uriVariables, $context);
    }
}
