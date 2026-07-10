<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\TaskStatus;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * End-to-end i18n seam: the TranslatableNormalizer overlays a translatable
 * base field with the active-locale value on the way out (falling back to the
 * base value), and the profile endpoint stores/validates preferredLanguage.
 *
 * Same isolation pattern as {@see NotificationsInboxTest}: one kernel,
 * everything inside a rolled-back transaction.
 */
final class TranslatableApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    public function testTranslatableFieldIsOverlaidToUserLocale(): void
    {
        [$user, $ws, $status] = $this->seedStatus(
            preferredLanguage: 'en',
            baseName: 'Offen',
            translations: ['name' => ['en' => 'Open']],
        );

        $this->getStatus($status, $user, $ws);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();
        // name overlaid to the user's locale …
        self::assertSame('Open', $data['name']);
        // … while the raw map is still exposed for editing UIs.
        self::assertSame(['name' => ['en' => 'Open']], $data['translations']);
    }

    public function testFallsBackToBaseValueWhenTranslationMissing(): void
    {
        // User prefers en, but this status has only a fr translation → base wins.
        [$user, $ws, $status] = $this->seedStatus(
            preferredLanguage: 'en',
            baseName: 'Offen',
            translations: ['name' => ['fr' => 'Ouvert']],
        );

        $this->getStatus($status, $user, $ws);

        self::assertSame('Offen', $this->json()['name']);
    }

    public function testProfilePreferredLanguageRoundTripAndValidation(): void
    {
        $user = $this->user('lang.pref@example.test', preferredLanguage: null);
        $this->em->flush();
        $token = $this->token($user);

        // GET advertises the supported set.
        $this->request('GET', '/v1/me/profile', $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertContains('de', $this->json()['supportedLanguages']);
        self::assertNull($this->json()['preferredLanguage']);

        // PATCH a supported locale → stored + echoed back.
        $this->request('PATCH', '/v1/me/profile', $token, ['preferredLanguage' => 'de']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('de', $this->json()['preferredLanguage']);

        // PATCH null clears the preference.
        $this->request('PATCH', '/v1/me/profile', $token, ['preferredLanguage' => null]);
        self::assertNull($this->json()['preferredLanguage']);

        // PATCH an unsupported locale → 400, nothing persisted.
        $this->request('PATCH', '/v1/me/profile', $token, ['preferredLanguage' => 'zz']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    // --- helpers ----------------------------------------------------

    /**
     * @param array<string, array<string, string>> $translations
     *
     * @return array{0: User, 1: Workspace, 2: TaskStatus}
     */
    private function seedStatus(string $preferredLanguage, string $baseName, array $translations): array
    {
        $ws = (new Workspace())
            ->setName('I18n WS')
            ->setSlug('i18n-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        $user = $this->user('i18n.' . substr(Uuid::v7()->toRfc4122(), 0, 8) . '@example.test', $preferredLanguage);

        $member = (new WorkspaceMember())
            ->setWorkspace($ws)
            ->setUser($user)
            ->setRole(WorkspaceMemberRole::Owner);
        $this->em->persist($member);

        $status = (new TaskStatus())
            ->setName($baseName)
            ->setWorkspace($ws)
            ->setTranslations($translations);
        $this->em->persist($status);

        $this->em->flush();

        return [$user, $ws, $status];
    }

    private function getStatus(TaskStatus $status, User $user, Workspace $ws): void
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token($user),
            'HTTP_X_WORKSPACE_ID' => $ws->getId()?->toRfc4122() ?? '',
        ];
        $this->client->request('GET', '/v1/task_statuses/' . ($status->getId()?->toRfc4122() ?? ''), [], [], $server);
    }

    private function user(string $email, ?string $preferredLanguage): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('T')
            ->setLastName('User')
            ->setRoles([])
            ->setPreferredLanguage($preferredLanguage);
        $user->setPassword('x'); // never used (JWT-minted in tests)
        $this->em->persist($user);

        return $user;
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?string $token = null, ?array $body = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $content = $body !== null ? json_encode($body, \JSON_THROW_ON_ERROR) : null;
        $this->client->request($method, $uri, [], [], $server, $content);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
