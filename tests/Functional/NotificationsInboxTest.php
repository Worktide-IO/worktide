<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\NotificationType;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Staff notification inbox (`/v1/me/notifications`) — keyset pagination, unread
 * count, per-item + bulk read, and the recipient-scoping guarantee (a user can
 * neither see nor mark another user's notifications).
 *
 * Same isolation pattern as {@see \App\Tests\Functional\Portal\PortalEndpointsTest}:
 * one kernel, everything inside a rolled-back transaction.
 */
final class NotificationsInboxTest extends WebTestCase
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

    public function testListIsScopedPaginatedAndCountsUnread(): void
    {
        $ctx = $this->seed();
        $token = $this->token($ctx['alice']);

        // Page 1 (limit 2): newest first, nextCursor present (3 total for Alice).
        $this->request('GET', '/v1/me/notifications?limit=2', $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $page1 = $this->json();
        self::assertCount(2, $page1['items']);
        self::assertSame(3, $page1['unreadCount']);
        self::assertNotNull($page1['nextCursor']);
        // Bob's notification never leaks into Alice's feed.
        foreach ($page1['items'] as $item) {
            self::assertNotSame('Bob only', $item['title']);
        }

        // Page 2 via cursor: the remaining 1, no further cursor.
        $this->request('GET', '/v1/me/notifications?limit=2&cursor=' . $page1['nextCursor'], $token);
        $page2 = $this->json();
        self::assertCount(1, $page2['items']);
        self::assertNull($page2['nextCursor']);
    }

    public function testMarkReadIsPerItemAndCannotTouchAnotherUsersRow(): void
    {
        $ctx = $this->seed();
        $aliceToken = $this->token($ctx['alice']);

        // Alice marks one of her own → unread 3 → 2.
        $this->request('POST', '/v1/me/notifications/' . $ctx['aliceFirstId'] . '/read', $aliceToken);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(2, $this->json()['unreadCount']);

        // Alice tries to mark Bob's notification: silently a no-op for her,
        // and Bob's stays unread.
        $this->request('POST', '/v1/me/notifications/' . $ctx['bobId'] . '/read', $aliceToken);
        self::assertSame(2, $this->json()['unreadCount']);

        $this->request('GET', '/v1/me/notifications', $this->token($ctx['bob']));
        self::assertSame(1, $this->json()['unreadCount']);
    }

    public function testReadAllClearsOnlyTheCallersInbox(): void
    {
        $ctx = $this->seed();

        $this->request('POST', '/v1/me/notifications/read-all', $this->token($ctx['alice']));
        self::assertSame(0, $this->json()['unreadCount']);

        // Bob is untouched.
        $this->request('GET', '/v1/me/notifications', $this->token($ctx['bob']));
        self::assertSame(1, $this->json()['unreadCount']);
    }

    // --- helpers ----------------------------------------------------

    /**
     * @return array{alice: User, bob: User, aliceFirstId: string, bobId: string}
     */
    private function seed(): array
    {
        $ws = (new Workspace())
            ->setName('Notif WS')
            ->setSlug('notif-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings([]);
        $this->em->persist($ws);

        $alice = $this->user('alice.notif@example.test');
        $bob = $this->user('bob.notif@example.test');

        // Three for Alice (persist order = chronological; UUIDv7 keeps it).
        $first = $this->notification($alice, $ws, 'Alice #1');
        $this->notification($alice, $ws, 'Alice #2');
        $this->notification($alice, $ws, 'Alice #3');
        $bobN = $this->notification($bob, $ws, 'Bob only');

        $this->em->flush();

        return [
            'alice' => $alice,
            'bob' => $bob,
            'aliceFirstId' => $first->getId()?->toRfc4122() ?? '',
            'bobId' => $bobN->getId()?->toRfc4122() ?? '',
        ];
    }

    private function user(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('T')
            ->setLastName('User')
            ->setRoles([]);
        $user->setPassword('x'); // never used (JWT-minted in tests)
        $this->em->persist($user);

        return $user;
    }

    private function notification(User $recipient, Workspace $ws, string $title): Notification
    {
        $n = new Notification(
            recipient: $recipient,
            type: NotificationType::System,
            title: $title,
            link: '/tasks',
            workspace: $ws,
        );
        $this->em->persist($n);

        return $n;
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
