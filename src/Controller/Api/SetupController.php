<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Repository\UserRepository;
use App\Service\Search\MeilisearchClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use League\Flysystem\FilesystemOperator;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use App\Security\RefreshTokenCookieFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * First-run setup wizard endpoints (public, under /v1/setup).
 *
 * A freshly deployed instance has an empty database — nobody can log in. These
 * endpoints let the web app detect that state, run a reachability check, and
 * bootstrap the first admin + workspace.
 *
 * Intentionally PUBLIC (see security.yaml). `POST /v1/setup/init` self-locks:
 * it refuses with 409 as soon as any user exists, so the public surface only
 * matters on a brand-new install.
 *
 * NOTE: unlike MeController these routes carry NO `host:` constraint, so they
 * resolve on both the ddev host and the production API host.
 */
final class SetupController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly JWTTokenManagerInterface $jwt,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly MeilisearchClientFactory $meiliFactory,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'file_storage.filesystem')]
        private readonly FilesystemOperator $filesystem,
        #[Autowire('%env(string:SEARCH_PROVIDER)%')]
        private readonly string $searchProvider,
        #[Autowire('%env(string:MERCURE_URL)%')]
        private readonly string $mercureUrl,
    ) {}

    /** Refresh-token TTL in seconds (mirrors gesdinet_jwt_refresh_token.ttl: 30 days). */
    private const REFRESH_TTL = 2592000;

    /** Is a first-run setup still required? (true when no user exists yet.) */
    #[Route(path: '/v1/setup/status', name: 'api_setup_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(['needsSetup' => $this->needsSetup()]);
    }

    /**
     * Reachability check for the core dependencies. Only meaningful before
     * setup — returns 409 once the instance is initialised (keeps the public
     * info surface minimal).
     */
    #[Route(path: '/v1/setup/health', name: 'api_setup_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        if (!$this->needsSetup()) {
            return new JsonResponse(['error' => 'already_initialized'], 409);
        }

        return new JsonResponse([
            'database' => $this->checkDatabase(),
            'storage' => $this->checkStorage(),
            'search' => $this->checkSearch(),
            'mercure' => $this->checkMercure(),
        ]);
    }

    /**
     * Create the first admin user + workspace and log them straight in.
     *
     * Body: { email, password, workspaceName, firstName? }
     * Returns: { token, refresh_token, workspaceId, userId } — same shape the
     * frontend authProvider parses from /v1/auth/login.
     */
    #[Route(path: '/v1/setup/init', name: 'api_setup_init', methods: ['POST'])]
    public function init(Request $request): JsonResponse
    {
        // Self-lock: the endpoint is public, so it must refuse once ANY user
        // exists. Guard first, then create in a single flush.
        if (!$this->needsSetup()) {
            return new JsonResponse(['error' => 'already_initialized'], 409);
        }

        $payload = $this->payload($request);
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $workspaceName = trim((string) ($payload['workspaceName'] ?? ''));
        $firstName = trim((string) ($payload['firstName'] ?? ''));

        $errors = [];
        if ($email === '' || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email address is required.';
        }
        if (\strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }
        if ($workspaceName === '') {
            $errors['workspaceName'] = 'A workspace name is required.';
        }
        if ($errors !== []) {
            return new JsonResponse(['error' => 'validation_failed', 'fields' => $errors], 422);
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName('');
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $workspace = (new Workspace())
            ->setName($workspaceName)
            ->setSlug($this->slugify($workspaceName))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin');

        $member = (new WorkspaceMember())
            ->setWorkspace($workspace)
            ->setUser($user)
            ->setRole(WorkspaceMemberRole::Owner);

        $this->em->persist($user);
        $this->em->persist($workspace);
        $this->em->persist($member);
        $this->em->flush();

        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, self::REFRESH_TTL);
        $this->refreshTokenManager->save($refreshToken);

        // M1: refresh token as an httpOnly cookie (not the JSON body), matching
        // the login flow — the SPA holds only the access token, in memory.
        $response = new JsonResponse([
            'token' => $this->jwt->create($user),
            'workspaceId' => $workspace->getId()?->toRfc4122(),
            'userId' => $user->getId()?->toRfc4122(),
        ], 201);
        $response->headers->setCookie(RefreshTokenCookieFactory::create($refreshToken->getRefreshToken(), self::REFRESH_TTL));

        return $response;
    }

    private function needsSetup(): bool
    {
        return $this->users->count([]) === 0;
    }

    /** @return array<string, mixed> */
    private function checkDatabase(): array
    {
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function checkStorage(): array
    {
        try {
            // A read that touches the backend without writing anything.
            $this->filesystem->fileExists('.worktide-health-probe');
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function checkSearch(): array
    {
        if (strtolower(trim($this->searchProvider)) !== 'meili'
            && strtolower(trim($this->searchProvider)) !== 'meilisearch') {
            return ['skipped' => true, 'reason' => 'using database (SEARCH_PROVIDER=' . $this->searchProvider . ')'];
        }
        try {
            $client = $this->meiliFactory->create();
            if ($client === null) {
                return ['ok' => false, 'error' => 'MEILISEARCH_DSN not configured'];
            }
            $client->health();
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function checkMercure(): array
    {
        $url = trim($this->mercureUrl);
        if ($url === '') {
            return ['skipped' => true, 'reason' => 'MERCURE_URL not configured'];
        }
        try {
            // A GET without a valid subscriber JWT returns 401/405 when the hub
            // is reachable; a transport error means it is down.
            $status = $this->httpClient->request('GET', $url, ['timeout' => 4])->getStatusCode();
            return ['ok' => true, 'status' => $status];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $raw = $request->getContent();
        if (!\is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        return \is_array($decoded) ? $decoded : [];
    }

    private function slugify(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = mb_substr($slug, 0, 60);
        return $slug === '' ? 'workspace' : $slug;
    }
}
