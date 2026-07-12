<?php

declare(strict_types=1);

namespace App\Tests\Functional\Portal;

use App\Entity\Contact;
use App\Entity\Customer;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\FileTarget;
use App\Entity\File;
use App\Entity\FileVersion;
use App\Entity\Folder;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Tenant isolation for the portal customer file area: a portal contact must see,
 * download and upload ONLY files/folders of their own customer — never another
 * customer's, and never staff-hidden ones. Runs in a rolled-back transaction.
 */
final class PortalFilesIsolationTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

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

    public function testContactSeesOnlyOwnCustomerFiles(): void
    {
        $ctx = $this->seed();

        $this->request('GET', '/v1/portal/files', $this->token($ctx['portalA']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();

        $folderNames = array_column($data['folders'], 'name');
        $fileNames = array_column($data['files'], 'name');

        self::assertContains('A-Ordner', $folderNames);
        self::assertContains('a-visible.txt', $fileNames);
        // Foreign customer's items and own hidden item must be absent.
        self::assertNotContains('B-Ordner', $folderNames);
        self::assertNotContains('b-secret.txt', $fileNames);
        self::assertNotContains('a-hidden.txt', $fileNames);
    }

    public function testForeignFolderNavigationIs404(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/files?folder=' . $ctx['folderB'], $this->token($ctx['portalA']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testForeignFileDownloadIs404(): void
    {
        $ctx = $this->seed();
        // Contact A must not download customer B's file.
        $this->request('GET', '/v1/portal/files/' . $ctx['fileB'] . '/content', $this->token($ctx['portalA']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        // …and symmetrically B must not download A's file.
        $this->request('GET', '/v1/portal/files/' . $ctx['fileAVisible'] . '/content', $this->token($ctx['portalB']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadLandsUnderOwnCustomerAndIsDownloadable(): void
    {
        $ctx = $this->seed();
        $token = $this->token($ctx['portalA']);

        $this->uploadFile('/v1/portal/files', $token, 'brief.txt', 'hallo welt');
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = $this->json();
        self::assertSame('brief.txt', $created['name']);

        // It belongs to customer A (target=Customer, targetId=A) — verified in DB.
        $file = $this->em->getRepository(File::class)->find(Uuid::fromString($created['id']));
        self::assertInstanceOf(File::class, $file);
        self::assertSame(FileTarget::Customer, $file->getTarget());
        self::assertSame($ctx['customerA'], $file->getTargetId()->toRfc4122());

        // A can download their fresh upload (200). Byte round-trip through the
        // StreamedResponse is covered by the Phase-1 API smoke; the test client
        // consumes the stream so getContent() can't re-read it here.
        $this->request('GET', '/v1/portal/files/' . $created['id'] . '/content', $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array{portalA: User, portalB: User, customerA: string, folderB: string, fileB: string, fileAVisible: string}
     */
    private function seed(): array
    {
        $ws = (new Workspace())
            ->setName('Files WS')
            ->setSlug('files-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['files' => true]]]);
        $this->em->persist($ws);

        // Customer A + its portal contact.
        $customerA = $this->customer($ws, 'Kunde A');
        $portalA = $this->user('a.contact@example.test');
        $this->em->persist(
            (new Contact())->setCustomer($customerA)->setFirstName('Anna')->setLastName('A')
                ->setEmail('a.contact@example.test')->setLinkedUser($portalA),
        );
        $folderA = $this->folder($ws, $customerA, 'A-Ordner', false);
        $this->file($ws, $customerA, 'a-visible.txt', null, false);
        $this->file($ws, $customerA, 'a-hidden.txt', null, true); // staff-hidden → invisible to portal

        // Customer B + its portal contact + a folder/file that A must never see.
        $customerB = $this->customer($ws, 'Kunde B');
        $portalB = $this->user('b.contact@example.test');
        $this->em->persist(
            (new Contact())->setCustomer($customerB)->setFirstName('Bert')->setLastName('B')
                ->setEmail('b.contact@example.test')->setLinkedUser($portalB),
        );
        $folderB = $this->folder($ws, $customerB, 'B-Ordner', false);
        $fileB = $this->file($ws, $customerB, 'b-secret.txt', null, false);
        $fileAVisible = $this->em->getRepository(File::class)
            ->findOneBy(['targetId' => $customerA->getId(), 'name' => 'a-visible.txt']);

        $this->em->flush();
        $ids = [
            'portalA' => $portalA,
            'portalB' => $portalB,
            'customerA' => $customerA->getId()?->toRfc4122() ?? '',
            'folderB' => $folderB->getId()?->toRfc4122() ?? '',
            'fileB' => $fileB->getId()?->toRfc4122() ?? '',
            'fileAVisible' => $fileAVisible?->getId()?->toRfc4122() ?? '',
        ];
        $this->em->clear();

        return $ids;
    }

    private function user(string $email): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('U')->setRoles(['ROLE_PORTAL']);
        $user->setPassword('noop');
        $this->em->persist($user);

        return $user;
    }

    private function customer(Workspace $ws, string $name): Customer
    {
        $customer = (new Customer())->setWorkspace($ws)->setName($name)->setIsCompany(true)
            ->setStatus(CustomerStatus::Active)->setPortalEnabled(true);
        $this->em->persist($customer);

        return $customer;
    }

    private function folder(Workspace $ws, Customer $customer, string $name, bool $hidden): Folder
    {
        $folder = (new Folder())->setWorkspace($ws)->setTarget(FileTarget::Customer)
            ->setTargetId($customer->getId())->setName($name)->setIsHiddenForConnectUsers($hidden);
        $this->em->persist($folder);

        return $folder;
    }

    private function file(Workspace $ws, Customer $customer, string $name, ?Folder $folder, bool $hidden): File
    {
        $file = (new File())->setWorkspace($ws)->setTarget(FileTarget::Customer)
            ->setTargetId($customer->getId())->setFolder($folder)->setName($name)
            ->setMimeType('text/plain')->setIsHiddenForConnectUsers($hidden);
        $this->em->persist($file);
        // Minimal version row so the entity is well-formed (no bytes needed for
        // the isolation assertions — those never reach storage).
        $version = (new FileVersion())->setFile($file)->setVersionNumber(1)
            ->setOriginalFilename($name)->setMimeType('text/plain')
            ->setChecksum('seed')->setStoragePath('seed')->setSize(0);
        $this->em->persist($version);
        $file->setCurrentVersion($version);

        return $file;
    }

    private function request(string $method, string $uri, ?string $token = null): void
    {
        $server = ['HTTP_HOST' => self::HOST, 'CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, [], [], $server);
    }

    private function uploadFile(string $uri, string $token, string $filename, string $contents): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pf');
        file_put_contents($tmp, $contents);
        $upload = new UploadedFile($tmp, $filename, 'text/plain', null, true);
        $this->client->request('POST', $uri, [], ['file' => $upload], [
            'HTTP_HOST' => self::HOST,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
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
