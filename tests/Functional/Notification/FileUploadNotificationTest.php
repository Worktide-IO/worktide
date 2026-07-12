<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\FileTarget;
use App\Entity\Enum\NotificationType;
use App\Entity\File;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UserPreferences;
use App\Entity\Workspace;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The file-upload notification resolvers, exercised through the real domain-event
 * → dispatch pipeline (create a File, flush, assert the right Notification rows
 * appear). Covers both directions + the hidden-file gate, and the debounce
 * sweep's window/mark-delivered behaviour. Runs in a rolled-back transaction.
 */
final class FileUploadNotificationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
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

    public function testCustomerUploadNotifiesAccountManager(): void
    {
        [$ws, $customer, $manager, $portalUsers] = $this->seed();

        $this->uploadFile($ws, $customer, 'kunde-datei.pdf', uploadedBy: $portalUsers[0], hidden: false);

        $forManager = $this->notificationsFor($manager, NotificationType::CustomerFileUpload);
        self::assertCount(1, $forManager);
        self::assertSame('kunde-datei.pdf', $forManager[0]->getBody());
        // The customer contacts are NOT notified about their own side.
        self::assertCount(0, $this->notificationsFor($portalUsers[0], NotificationType::FileShared));
    }

    public function testStaffUploadNotifiesPortalContacts(): void
    {
        [$ws, $customer, $manager, $portalUsers] = $this->seed();

        $this->uploadFile($ws, $customer, 'freigabe.pdf', uploadedBy: $manager, hidden: false);

        // Both active portal contacts get a FileShared notification…
        self::assertCount(1, $this->notificationsFor($portalUsers[0], NotificationType::FileShared));
        self::assertCount(1, $this->notificationsFor($portalUsers[1], NotificationType::FileShared));
        // …and the uploading staff user is not notified.
        self::assertCount(0, $this->notificationsFor($manager, NotificationType::CustomerFileUpload));
    }

    public function testHiddenStaffUploadNotifiesNobody(): void
    {
        [$ws, $customer, , $portalUsers] = $this->seed();

        $this->uploadFile($ws, $customer, 'intern.pdf', uploadedBy: null, hidden: true);

        self::assertCount(0, $this->notificationsFor($portalUsers[0], NotificationType::FileShared));
        self::assertCount(0, $this->notificationsFor($portalUsers[1], NotificationType::FileShared));
    }

    public function testBatchSweepMarksDeliveredOnlyAfterWindow(): void
    {
        [$ws, $customer, $manager, $portalUsers] = $this->seed();
        // Manager opts out of async channels so the sweep can mark delivered
        // without needing real email/chat egress in the test.
        $prefs = (new UserPreferences($manager))
            ->setNotificationPreferences(['email' => false, 'chat' => false, 'delayMinutes' => 30]);
        $this->em->persist($prefs);

        $this->uploadFile($ws, $customer, 'a.pdf', uploadedBy: $portalUsers[0], hidden: false);
        $notifications = $this->notificationsFor($manager, NotificationType::CustomerFileUpload);
        self::assertCount(1, $notifications);
        self::assertNull($notifications[0]->getDeliveredAt(), 'fresh notification is not yet delivered');

        $command = self::getContainer()->get(\App\Command\NotificationsFlushBatchCommand::class);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        // Window not elapsed → still pending.
        $tester->execute([]);
        $this->em->refresh($notifications[0]);
        self::assertNull($notifications[0]->getDeliveredAt(), 'within the delay window it stays pending');

        // Backdate past the window → the next sweep delivers + stamps it.
        $this->em->getConnection()->executeStatement(
            'UPDATE notifications SET occurred_at = :t WHERE id = :id',
            ['t' => (new \DateTimeImmutable('-31 minutes'))->format('Y-m-d H:i:s'), 'id' => $notifications[0]->getId()?->toBinary()],
            ['id' => \Doctrine\DBAL\ParameterType::BINARY],
        );
        $this->em->refresh($notifications[0]);
        $tester->execute([]);
        $this->em->refresh($notifications[0]);
        self::assertNotNull($notifications[0]->getDeliveredAt(), 'after the window it is delivered');
    }

    /**
     * @return array{0: Workspace, 1: Customer, 2: User, 3: list<User>}
     */
    private function seed(): array
    {
        $ws = (new Workspace())->setName('Notif WS')
            ->setSlug('notif-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')->setTimezone('Europe/Berlin');
        $this->em->persist($ws);

        $manager = $this->user('mgr@example.test', []);
        $portal1 = $this->user('p1@example.test', ['ROLE_PORTAL']);
        $portal2 = $this->user('p2@example.test', ['ROLE_PORTAL']);

        $customer = (new Customer())->setWorkspace($ws)->setName('Kunde')->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)->setPortalEnabled(true)->setAccountManager($manager);
        $this->em->persist($customer);

        foreach ([['Ann', $portal1, 'p1@example.test'], ['Ben', $portal2, 'p2@example.test']] as [$first, $u, $mail]) {
            $this->em->persist(
                (new Contact())->setCustomer($customer)->setFirstName($first)->setLastName('K')
                    ->setEmail($mail)->setLinkedUser($u),
            );
        }

        $this->em->flush();

        // Detach + reload so entities behave like a real request: the resolver
        // reaches contacts via a fresh lazy collection, not the empty in-memory
        // one a just-created entity carries.
        $ids = [
            'ws' => $ws->getId(),
            'customer' => $customer->getId(),
            'manager' => $manager->getId(),
            'p1' => $portal1->getId(),
            'p2' => $portal2->getId(),
        ];
        $this->em->clear();

        $customer = $this->em->find(Customer::class, $ids['customer']);
        \assert($customer instanceof Customer);

        return [
            $this->em->find(Workspace::class, $ids['ws']),
            $customer,
            $this->em->find(User::class, $ids['manager']),
            [$this->em->find(User::class, $ids['p1']), $this->em->find(User::class, $ids['p2'])],
        ];
    }

    private function uploadFile(Workspace $ws, Customer $customer, string $name, ?User $uploadedBy, bool $hidden): void
    {
        $file = (new File())->setWorkspace($ws)->setTarget(FileTarget::Customer)
            ->setTargetId($customer->getId())->setName($name)->setMimeType('application/pdf')
            ->setIsHiddenForConnectUsers($hidden);
        if ($uploadedBy !== null) {
            $file->setUploadedBy($uploadedBy);
        }
        $this->em->persist($file);
        $this->em->flush(); // triggers file.created → notification dispatch
    }

    /**
     * @return list<Notification>
     */
    private function notificationsFor(User $user, NotificationType $type): array
    {
        /** @var NotificationRepository $repo */
        $repo = $this->em->getRepository(Notification::class);

        return $repo->findBy(['recipient' => $user, 'type' => $type]);
    }

    private function user(string $email, array $roles): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('U')->setRoles($roles);
        $user->setPassword('noop');
        $this->em->persist($user);

        return $user;
    }
}
