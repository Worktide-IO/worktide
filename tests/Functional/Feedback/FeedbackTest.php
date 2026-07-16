<?php

declare(strict_types=1);

namespace App\Tests\Functional\Feedback;

use App\Command\FeedbackBootstrapCommand;
use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\NotificationType;
use App\Entity\Enum\WorkspaceMemberRole;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceMember;
use App\Service\Feedback\FeedbackProjectLocator;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * The shared feedback board's security-critical properties (see
 * docs/feedback-board-plan.md): cross-tenant anonymization, the belt-and-
 * suspenders that feedback tasks never surface through the Task resource,
 * super-admin de-anonymization, the reply→reporter notification, and the
 * per-workspace portal toggle.
 *
 * Same isolation pattern as {@see \App\Tests\Functional\TenantScopingTest}:
 * one kernel, everything in a rolled-back transaction. The feedback board is
 * provisioned per-test via the bootstrap command (rolled back with the rest).
 */
final class FeedbackTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        // Provision the platform workspace + WTFB project + trackers/statuses
        // inside the test transaction (rolled back in tearDown).
        $cmd = self::getContainer()->get(FeedbackBootstrapCommand::class);
        (new CommandTester($cmd))->execute([]);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    public function testCrossTenantSubmissionsAreAnonymizedToOtherUsers(): void
    {
        $alice = $this->member($this->user('alice.fb@example.test'), $this->workspace('fb-a'));
        $bob = $this->member($this->user('bob.fb@example.test'), $this->workspace('fb-b'));
        $this->em->flush();

        // Alice (workspace A) files a bug.
        $this->post('/v1/feedback', $this->token($alice), ['title' => 'Alice bug', 'category' => 'bug']);
        self::assertSame(201, $this->code());
        $id = $this->json()['id'];

        // Bob (workspace B) sees it on the global board — but anonymized.
        $this->get('/v1/feedback', $this->token($bob));
        self::assertSame(200, $this->code());
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('alice.fb@example.test', $raw, 'submitter email must never leak');

        $ticket = $this->itemById($id);
        self::assertNotNull($ticket, 'the ticket is visible cross-tenant (one global board)');
        self::assertSame('user', $ticket['authorLabel']);
        self::assertFalse($ticket['isMine']);
        self::assertArrayNotHasKey('submitter', $ticket, 'non-super-admins never see the submitter');

        // Alice sees her own as "you".
        $this->get('/v1/feedback', $this->token($alice));
        $own = $this->itemById($id);
        self::assertSame('you', $own['authorLabel']);
        self::assertTrue($own['isMine']);
    }

    public function testFeedbackTasksAreNotExposedThroughTheTaskResource(): void
    {
        $alice = $this->member($this->user('alice2.fb@example.test'), $this->workspace('fb-a2'));
        $this->em->flush();

        $this->post('/v1/feedback', $this->token($alice), ['title' => 'Hidden from tasks', 'category' => 'bug']);
        self::assertSame(201, $this->code());

        // The belt-and-suspenders: even filtering by the feedback project, a
        // non-member's Task collection is scoped to empty by WorkspaceScopeExtension.
        $wtfb = self::getContainer()->get(FeedbackProjectLocator::class)->feedbackProjectOrFail();
        $this->get('/v1/tasks?project=' . $wtfb->getId()?->toRfc4122(), $this->token($alice));
        self::assertSame(200, $this->code());
        self::assertCount(0, $this->members());
    }

    public function testSuperAdminSeesSubmitterIdentity(): void
    {
        $alice = $this->member($this->user('alice3.fb@example.test'), $this->workspace('fb-a3'));
        $admin = $this->user('root.fb@example.test', ['ROLE_SUPER_ADMIN']);
        $this->em->flush();

        $this->post('/v1/feedback', $this->token($alice), ['title' => 'Seen by admin', 'category' => 'feature']);
        $id = $this->json()['id'];

        $this->get('/v1/feedback', $this->token($admin));
        $ticket = $this->itemById($id);
        self::assertArrayHasKey('submitter', $ticket);
        self::assertSame('T User', $ticket['submitter']['name'] ?? null);
    }

    public function testReplyNotifiesTheReporter(): void
    {
        $alice = $this->member($this->user('alice4.fb@example.test'), $this->workspace('fb-a4'));
        $bob = $this->member($this->user('bob4.fb@example.test'), $this->workspace('fb-b4'));
        $this->em->flush();

        $this->post('/v1/feedback', $this->token($alice), ['title' => 'Needs a reply', 'category' => 'bug']);
        $id = $this->json()['id'];

        // Bob replies → Alice (the reporter) gets a feedback_reply notification.
        $this->post('/v1/feedback/' . $id . '/replies', $this->token($bob), ['content' => 'On it.']);
        self::assertSame(201, $this->code());

        $notifs = $this->em->getRepository(Notification::class)
            ->findBy(['recipient' => $alice, 'type' => NotificationType::FeedbackReply]);
        self::assertCount(1, $notifs, 'the reporter is notified of a reply');

        // Alice replying to herself does NOT notify herself.
        $this->post('/v1/feedback/' . $id . '/replies', $this->token($alice), ['content' => 'Thanks']);
        $self = $this->em->getRepository(Notification::class)
            ->findBy(['recipient' => $alice, 'type' => NotificationType::FeedbackReply]);
        self::assertCount(1, $self, 'no self-notification for the reporter’s own reply');
    }

    public function testPortalToggleGatesClientsButNotStaff(): void
    {
        $ws = $this->workspace('fb-portal', ['portal' => ['enabled' => true, 'features' => ['feedback' => false]]]);
        $customer = (new Customer())->setName('Acme Portal');
        $customer->setWorkspace($ws);
        $customer->setPortalEnabled(true);
        $this->em->persist($customer);

        $portalUser = $this->user('portal.fb@example.test', ['ROLE_PORTAL']);
        $contact = (new Contact())->setFirstName('P')->setLastName('Client')->setEmail('portal.fb@example.test');
        $contact->setCustomer($customer);
        $contact->setWorkspace($ws);
        $contact->setIsActive(true);
        $contact->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $this->em->flush();

        // feedback OFF for this workspace's portal → 403.
        $this->get('/v1/portal/feedback', $this->token($portalUser));
        self::assertSame(403, $this->code());

        // Flip it on → 200.
        $ws->setSettings(['portal' => ['enabled' => true, 'features' => ['feedback' => true]]]);
        $this->em->flush();
        $this->get('/v1/portal/feedback', $this->token($portalUser));
        self::assertSame(200, $this->code());
    }

    // --- helpers -----------------------------------------------------------

    /** @param array<string, mixed> $settings */
    private function workspace(string $slugPrefix, array $settings = []): Workspace
    {
        $ws = (new Workspace())
            ->setName('FB ' . $slugPrefix)
            ->setSlug($slugPrefix . '-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setSettings($settings);
        $this->em->persist($ws);

        return $ws;
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('User')->setRoles($roles);
        $user->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    private function member(User $user, Workspace $ws): User
    {
        $m = (new WorkspaceMember())->setUser($user)->setWorkspace($ws)->setRole(WorkspaceMemberRole::Member);
        $this->em->persist($m);

        return $user;
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function get(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_HOST' => 'api.worktide.ddev.site',
        ]);
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, string $token, array $body): void
    {
        $this->client->request('POST', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'api.worktide.ddev.site',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function code(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    private function itemById(string $id): ?array
    {
        foreach ($this->json()['items'] ?? [] as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @return list<mixed> */
    private function members(): array
    {
        $data = $this->json();

        return $data['hydra:member'] ?? $data['member'] ?? [];
    }
}
