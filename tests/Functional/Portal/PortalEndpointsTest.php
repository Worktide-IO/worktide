<?php

declare(strict_types=1);

namespace App\Tests\Functional\Portal;

use App\Entity\AgreementLineItem;
use App\Entity\AgreementType;
use App\Entity\BrainstormNote;
use App\Entity\Comment;
use App\Entity\Contact;
use App\Entity\Enum\CommentTarget;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;
use App\Entity\Customer;
use App\Entity\CustomerAgreement;
use App\Entity\CustomerAgreementRevision;
use App\Entity\CustomerSystem;
use App\Entity\DomainEventLog;
use App\Entity\Enum\AgreementStatus;
use App\Entity\Enum\CustomerStatus;
use App\Entity\Enum\IdeaOrigin;
use App\Entity\Enum\IncidentKind;
use App\Entity\Enum\SystemEnvironment;
use App\Entity\Enum\SystemType;
use App\Entity\Enum\TaskDependencyType;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\PublicForm;
use App\Entity\PublicFormSubmission;
use App\Entity\SystemIncident;
use App\Entity\SystemUptimeDay;
use App\Entity\Task;
use App\Entity\TaskDependency;
use App\Entity\TaskStatus;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Functional smoke tests for the customer portal — exercises the real routing,
 * the ROLE_PORTAL firewall, per-feature flags and customer scoping end-to-end.
 *
 * Isolation: each test runs inside a DB transaction that is rolled back in
 * tearDown, so the shared dev database is left untouched. Portal routes require
 * the api host, so every request sets HTTP_HOST.
 */
final class PortalEndpointsTest extends WebTestCase
{
    private const HOST = 'api.worktide.ddev.site';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot(); // keep one kernel/connection so the tx holds across the request
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

    public function testUnauthenticatedIsRejected(): void
    {
        $this->request('GET', '/v1/portal/me');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testStaffTokenCannotAccessPortal(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/me', $this->token($ctx['staff']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testPortalTokenCannotAccessStaffEndpoint(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/tasks', $this->token($ctx['portalUser']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testPortalLoginBlockedWhenCustomerNotEnabled(): void
    {
        $ctx = $this->seed();

        // Take the customer's portal Freischaltung away → the PortalUserChecker
        // must reject the (still valid) JWT on the very next request.
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['linkedUser' => $ctx['portalUser']]);
        self::assertInstanceOf(Contact::class, $contact);
        $contact->getCustomer()->setPortalEnabled(false);
        $this->em->flush();
        $this->em->clear();

        $this->request('GET', '/v1/portal/me', $this->token($ctx['portalUser']));
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testMeReturnsCuratedContact(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/me', $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();
        self::assertSame('portal.contact@example.test', $data['contact']['email']);
        self::assertTrue($data['features']['tickets']);
        self::assertFalse($data['features']['monitoring']);
    }

    public function testTicketsAreScopedAndHiddenExcluded(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/tickets', $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $ids = array_column($this->json()['tickets'], 'identifier');

        self::assertContains('OWN-1', $ids);          // own visible ticket
        self::assertNotContains('OWN-HIDDEN', $ids);  // isHiddenForConnectUsers
        self::assertNotContains('FOR-1', $ids);       // another customer's ticket
    }

    public function testForeignTicketIs404(): void
    {
        $ctx = $this->seed();
        $this->request('GET', '/v1/portal/tickets/' . $ctx['foreignTaskId'], $this->token($ctx['portalUser']));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDisabledFeatureIs403(): void
    {
        $ctx = $this->seed(); // monitoring is OFF in the seed
        $this->request('GET', '/v1/portal/systems', $this->token($ctx['portalUser']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTicketAttachmentUploadListDownloadAndScoping(): void
    {
        $ctx = $this->seed();
        $token = $this->token($ctx['portalUser']);

        // Upload to an own, visible ticket.
        $this->uploadFile('/v1/portal/tickets/' . $ctx['ownTaskId'] . '/attachments', $token, 'notiz.txt', 'geheimer inhalt');
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $fileId = $this->json()['id'];
        self::assertSame('notiz.txt', $this->json()['name']);

        // It shows up in the ticket detail's attachments.
        $this->request('GET', '/v1/portal/tickets/' . $ctx['ownTaskId'], $token);
        self::assertSame(['notiz.txt'], array_column($this->json()['attachments'], 'name'));

        // Download serves the file as an attachment (bytes stream out — the
        // StreamedResponse body isn't capturable via BrowserKit, so assert the
        // status + Content-Disposition which prove the right file is served).
        $this->request('GET', '/v1/portal/tickets/' . $ctx['ownTaskId'] . '/attachments/' . $fileId . '/content', $token);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('notiz.txt', (string) $response->headers->get('Content-Disposition'));

        // Uploading to a FOREIGN ticket is rejected (404, not visible).
        $this->uploadFile('/v1/portal/tickets/' . $ctx['foreignTaskId'] . '/attachments', $token, 'x.txt', 'x');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testMonitoringExposesDerivedStatusAndOmitsSecrets(): void
    {
        $ctx = $this->seedMonitoring();
        $this->request('GET', '/v1/portal/systems', $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();

        self::assertCount(1, $data['systems']);
        $system = $data['systems'][0];

        // Live status is derived from the open Outage incident.
        self::assertSame('down', $system['status']);
        self::assertSame('Störung', $system['statusLabel']);
        // Uptime aggregate + sparkline come from the seeded rollup day.
        self::assertEquals(95.0, $system['uptimePct']);
        self::assertSame(420, $system['avgResponseMs']);
        self::assertCount(1, $system['uptimeDays']);

        // SECURITY: internal fields must never leak to the customer.
        self::assertArrayNotHasKey('credentialsNotes', $system);
        self::assertArrayNotHasKey('notes', $system);
        self::assertArrayNotHasKey('adminLoginUrl', $system);
        self::assertArrayNotHasKey('stagingUrl', $system);

        // The open incident shows up in "Vorfälle & Wartung".
        self::assertCount(1, $data['incidents']);
        self::assertTrue($data['incidents'][0]['open']);
        self::assertSame('Störung', $data['incidents'][0]['kindLabel']);

        // Default window is 30 days and the selectable set is advertised.
        self::assertSame(30, $data['windowDays']);
        self::assertSame([7, 30, 90], $data['availableWindows']);
    }

    public function testMonitoringWindowParamIsClamped(): void
    {
        $ctx = $this->seedMonitoring();
        $token = $this->token($ctx['portalUser']);

        // A supported window echoes back verbatim.
        $this->request('GET', '/v1/portal/systems?days=7', $token);
        self::assertSame(7, $this->json()['windowDays']);

        // An unsupported value falls back to the default rather than erroring.
        $this->request('GET', '/v1/portal/systems?days=999', $token);
        self::assertSame(30, $this->json()['windowDays']);
    }

    public function testDashboardAggregatesRealData(): void
    {
        $ctx = $this->seedDashboard();
        $this->request('GET', '/v1/portal/dashboard', $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();

        self::assertSame('Dash GmbH', $data['customerName']);

        // Retainer budget: 300 tracked minutes this month vs. 600 budget = 50 %.
        self::assertNotNull($data['budget']);
        self::assertSame(50, $data['budget']['pct']);
        self::assertSame(300, $data['budget']['consumedMinutes']);
        self::assertSame(600, $data['budget']['budgetMinutes']);

        // The successor task has an open predecessor → shows up as a blocker.
        self::assertSame(['DASH-2'], array_column($data['blockers'], 'identifier'));

        // Activity is curated: actor is redacted to Sie/Agentur, never staff PII.
        self::assertNotEmpty($data['activity']);
        foreach ($data['activity'] as $event) {
            self::assertContains($event['actor'], ['Sie', 'Agentur']);
            self::assertArrayNotHasKey('payload', $event);
        }
    }

    public function testAgreementExposesLineItemsAndTotal(): void
    {
        $ctx = $this->seedAgreements();
        $this->request('GET', '/v1/portal/agreements', $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();

        self::assertCount(1, $data['agreements']);
        $agreement = $data['agreements'][0];

        // Two lines: 1×10000 + 2×5000 = 20000, all recurring → monthly total.
        self::assertCount(2, $agreement['lineItems']);
        self::assertSame(20000, $agreement['totalCents']);
        self::assertTrue($agreement['totalIsRecurring']);

        // Quantity math: the 2×5000 line reports a 10000 line total.
        $qtyLine = array_values(array_filter($agreement['lineItems'], static fn ($l) => $l['quantity'] == 2.0))[0];
        self::assertSame(10000, $qtyLine['amountCents']);
    }

    public function testBrainstormBoardListsAndAppends(): void
    {
        $ctx = $this->seedBrainstorm();
        $token = $this->token($ctx['portalUser']);

        // The board starts with the seeded agency note (not mine).
        $this->request('GET', '/v1/portal/brainstorm', $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $notes = $this->json()['notes'];
        self::assertCount(1, $notes);
        self::assertSame('agency', $notes[0]['origin']);
        self::assertFalse($notes[0]['isMine']);

        // Posting appends a customer note attributed to me.
        $this->request('POST', '/v1/portal/brainstorm', $token, ['body' => 'Mein Beitrag']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = $this->json();
        self::assertSame('customer', $created['origin']);
        self::assertTrue($created['isMine']);
        self::assertSame('Alan Agr', $created['authorName']);

        // And it now shows up in the list (chronological, appended).
        $this->request('GET', '/v1/portal/brainstorm', $token);
        self::assertCount(2, $this->json()['notes']);
    }

    public function testNotificationsSurfaceAgencyReplyAndMarkRead(): void
    {
        $ctx = $this->seed();
        $token = $this->token($ctx['portalUser']);

        // An agency (staff-authored) comment on the customer's visible ticket.
        $task = $this->em->getRepository(Task::class)->find(Uuid::fromString($ctx['ownTaskId']));
        $staff = $this->em->getRepository(User::class)->findOneBy(['email' => 'portal.staff@example.test']);
        self::assertNotNull($task);
        $this->em->persist(
            (new Comment())->setWorkspace($task->getWorkspace())->setTarget(CommentTarget::Task)
                ->setTargetId($task->getId())->setAuthor($staff)->setContent('Antwort der Agentur')
                ->setIsHiddenForConnectUsers(false),
        );
        $this->em->flush();

        // It is persisted (via the DomainEventLog fan-out) as an unread
        // comment notification pointing at the ticket.
        $this->request('GET', '/v1/portal/notifications', $token);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();
        self::assertSame(1, $data['unreadCount']);
        self::assertSame('comment', $data['items'][0]['type']);
        self::assertSame('Neue Antwort auf OWN-1', $data['items'][0]['title']);
        self::assertSame('/tickets/' . $ctx['ownTaskId'], $data['items'][0]['link']);
        self::assertFalse($data['items'][0]['read']);
        $notificationId = $data['items'][0]['id'];

        // Per-item mark-read flips exactly that item and decrements the badge.
        $this->request('POST', '/v1/portal/notifications/' . $notificationId . '/read', $token);
        self::assertSame(0, $this->json()['unreadCount']);

        // The legacy bulk mark-read alias still works (no-op here, already read).
        $this->request('POST', '/v1/portal/notifications/mark-read', $token);
        self::assertSame(0, $this->json()['unreadCount']);
        $this->request('GET', '/v1/portal/notifications', $token);
        $after = $this->json();
        self::assertSame(0, $after['unreadCount']);
        self::assertTrue($after['items'][0]['read']);
    }

    public function testInvoicesListWithDerivedOverdue(): void
    {
        $ctx = $this->seedInvoices();
        $this->request('GET', '/v1/portal/invoices', $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $invoices = $this->json()['invoices'];

        self::assertCount(2, $invoices);
        // Newest first: the open (issued -20d) precedes the paid (issued -30d).
        self::assertSame('overdue', $invoices[0]['status']); // Open + past due → derived overdue
        self::assertSame('Überfällig', $invoices[0]['statusLabel']);
        self::assertSame('paid', $invoices[1]['status']);
        self::assertSame(5000, $invoices[0]['totalCents']);
    }

    public function testInvoicesRequireFeature(): void
    {
        $ctx = $this->seed(); // invoices flag OFF
        $this->request('GET', '/v1/portal/invoices', $this->token($ctx['portalUser']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testFormShowExposesV2SchemaWithoutInternals(): void
    {
        $ctx = $this->seedForms();
        $this->request('GET', '/v1/portal/forms/' . $ctx['formId'], $this->token($ctx['portalUser']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = $this->client->getResponse()->getContent();
        $json = $this->json();

        self::assertSame(2, $json['schema']['version']);
        self::assertCount(1, $json['schema']['pages']);
        // The Tally-like renderer gets logic + calc.
        self::assertNotEmpty($json['schema']['logic']);
        // Internal routing/prefill source must NEVER reach the client.
        self::assertStringNotContainsString('mapsTo', (string) $body);
        self::assertStringNotContainsString('prefillFrom', (string) $body);
        self::assertStringNotContainsString('contact.id', (string) $body);
    }

    public function testFormFeatureIsGated(): void
    {
        $ctx = $this->seed(); // forms flag OFF
        $this->request('GET', '/v1/portal/forms', $this->token($ctx['portalUser']));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testFormSubmitAppliesBranchingPrefillAndFiresWebhookEvent(): void
    {
        $ctx = $this->seedForms();

        // has_site=no ⇒ site_url is branched off, so omitting it must NOT 422.
        // The client tries to spoof the hidden prefill field `cid`.
        $this->request('POST', '/v1/portal/forms/' . $ctx['formId'] . '/submit', $this->token($ctx['portalUser']), [
            'name' => 'Neuer Audit-Wunsch',
            'has_site' => 'no',
            'cid' => 'attacker-supplied',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $submission = $this->em->getRepository(PublicFormSubmission::class)->findOneBy([]);
        self::assertInstanceOf(PublicFormSubmission::class, $submission);
        $payload = $submission->getPayload();
        // Prefill is server-authoritative — the spoofed value is overwritten.
        self::assertSame($ctx['contactId'], $payload['cid']);
        // Branched-off field never entered the payload.
        self::assertArrayNotHasKey('site_url', $payload);
        // The materialized task took its title from the mapsTo=title field.
        self::assertSame('Neuer Audit-Wunsch', $submission->getCreatedTask()?->getTitle());

        // The webhook-feeding domain event fired for the accepted submission.
        $events = $this->em->getRepository(DomainEventLog::class)->findBy(['name' => 'publicformsubmission.created']);
        self::assertCount(1, $events);
    }

    public function testFormSubmitEnforcesActiveRequiredField(): void
    {
        $ctx = $this->seedForms();

        // has_site=yes ⇒ site_url becomes required; omitting it must 422.
        $this->request('POST', '/v1/portal/forms/' . $ctx['formId'] . '/submit', $this->token($ctx['portalUser']), [
            'name' => 'X',
            'has_site' => 'yes',
        ]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('site_url', $this->json()['errors']);
    }

    public function testPerContactGatingHidesAnEnabledFeature(): void
    {
        $ctx = $this->seedMonitoring(); // monitoring ON for the workspace
        $token = $this->token($ctx['portalUser']);

        // Baseline: the workspace feature is visible + reachable.
        $this->request('GET', '/v1/portal/me', $token);
        self::assertTrue($this->json()['features']['monitoring']);

        // Hide monitoring for THIS contact only.
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['email' => 'portal.mon@example.test']);
        $contact->setPortalHiddenFeatures(['monitoring']);
        $this->em->flush();

        // Now absent from /me and the endpoint is gated, though the workspace still has it on.
        $this->request('GET', '/v1/portal/me', $token);
        self::assertFalse($this->json()['features']['monitoring']);
        $this->request('GET', '/v1/portal/systems', $token);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAgreementInquiryRecordsQueryOnOpenOffer(): void
    {
        $ctx = $this->seedInquirableAgreement();
        $token = $this->token($ctx['portalUser']);
        $uri = '/v1/portal/agreements/' . $ctx['agreementId'] . '/inquiry';

        // Empty message rejected.
        $this->request('POST', $uri, $token, ['message' => '   ']);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        // A valid query is recorded; the offer stays open (canSign still true).
        $this->request('POST', $uri, $token, ['message' => 'Gilt der Preis auch 2027?']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->json();
        self::assertSame('Gilt der Preis auch 2027?', $data['inquiry']);
        self::assertNotNull($data['inquiredAt']);
        self::assertTrue($data['canSign']);
    }

    // --- helpers ----------------------------------------------------

    /** Multipart file upload — distinct from request() which sends JSON. */
    private function uploadFile(string $uri, string $token, string $filename, string $contents): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmp, $contents);
        $upload = new UploadedFile($tmp, $filename, 'text/plain', null, true);

        $this->client->request(
            'POST',
            $uri,
            [],
            ['file' => $upload],
            ['HTTP_HOST' => self::HOST, 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?string $token = null, ?array $body = null): void
    {
        $server = ['HTTP_HOST' => self::HOST, 'CONTENT_TYPE' => 'application/json'];
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

    /**
     * Build an isolated portal world: a workspace (portal on, only `tickets`
     * feature enabled), a customer with an external project holding a visible
     * + a hidden ticket, a linked portal user + a staff user, and a SECOND
     * customer whose ticket must stay invisible.
     *
     * @return array{portalUser: User, staff: User, ownTaskId: string, foreignTaskId: string}
     */
    private function seed(): array
    {
        $ws = (new Workspace())
            ->setName('Test WS')
            ->setSlug('test-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'monitoring' => false]]]);
        $this->em->persist($ws);

        $status = (new TaskStatus())->setWorkspace($ws)->setName('Offen')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsDefault(true);
        $this->em->persist($status);

        // One shared project status (unique per workspace+name).
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);

        $portalUser = $this->user('portal.contact@example.test', ['ROLE_PORTAL']);
        $staff = $this->user('portal.staff@example.test', []);

        // Own customer + external project + contact linked to the portal user.
        $customer = $this->customer($ws, 'Own GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Paula')->setLastName('Portal')
            ->setEmail('portal.contact@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $project = $this->project($ws, $customer, 'OWN', $projectStatus);
        $ownTask = $this->task($ws, $project, $status, 'OWN-1', false);
        $this->task($ws, $project, $status, 'OWN-HIDDEN', true);

        // Foreign customer + project + ticket — must never be visible.
        $foreignCustomer = $this->customer($ws, 'Foreign GmbH');
        $foreignProject = $this->project($ws, $foreignCustomer, 'FOR', $projectStatus);
        $foreignTask = $this->task($ws, $foreignProject, $status, 'FOR-1', false);

        $this->em->flush();
        // Detach the seed graph so the request reloads from DB (with working
        // lazy inverse-collections), like a real request would — otherwise the
        // in-memory Customer keeps its empty projects collection.
        $this->em->clear();

        return [
            'portalUser' => $portalUser,
            'staff' => $staff,
            'ownTaskId' => $ownTask->getId()?->toRfc4122() ?? '',
            'foreignTaskId' => $foreignTask->getId()?->toRfc4122() ?? '',
        ];
    }

    /**
     * A portal world with the forms feature ON: a v2 (Tally-like) form with a
     * required title field, a branch driver (`has_site`) that shows/requires
     * `site_url` only when "yes", and a hidden `cid` field prefilled from the
     * contact id.
     *
     * @return array{portalUser: User, formId: string, contactId: string}
     */
    private function seedForms(): array
    {
        $ws = (new Workspace())
            ->setName('Form WS')
            ->setSlug('form-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'forms' => true]]]);
        $this->em->persist($ws);

        $status = (new TaskStatus())->setWorkspace($ws)->setName('Offen')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsDefault(true);
        $this->em->persist($status);
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);

        $portalUser = $this->user('portal.forms@example.test', ['ROLE_PORTAL']);
        $customer = $this->customer($ws, 'Form GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Fritz')->setLastName('Form')
            ->setEmail('portal.forms@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $project = $this->project($ws, $customer, 'FRM', $projectStatus);

        $form = (new PublicForm())
            ->setWorkspace($ws)
            ->setProject($project)
            ->setSlug('audit-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setTitle('SEO-Audit')
            ->setFields([])
            ->setSchemaVersion(2)
            ->setDefaultStatus($status)
            ->setSchema([
                'pages' => [[
                    'id' => 'p1',
                    'title' => 'Basis',
                    'blocks' => [
                        ['id' => 'bn', 'key' => 'name', 'type' => 'text', 'label' => 'Betreff', 'required' => true, 'mapsTo' => 'title'],
                        ['id' => 'bh', 'key' => 'has_site', 'type' => 'select', 'label' => 'Website vorhanden?', 'options' => ['yes', 'no']],
                        ['id' => 'bu', 'key' => 'site_url', 'type' => 'url', 'label' => 'URL', 'required' => true],
                        ['id' => 'bc', 'key' => 'cid', 'type' => 'text', 'label' => '', 'hidden' => true, 'prefillFrom' => 'contact.id'],
                    ],
                ]],
                'logic' => [
                    ['if' => ['all' => [['field' => 'has_site', 'op' => 'eq', 'value' => 'yes']]],
                        'then' => ['action' => 'show', 'target' => 'bu']],
                ],
            ]);
        $this->em->persist($form);

        $this->em->flush();
        $formId = $form->getId()?->toRfc4122() ?? '';
        $contactId = $contact->getId()?->toRfc4122() ?? '';
        $this->em->clear();

        return ['portalUser' => $portalUser, 'formId' => $formId, 'contactId' => $contactId];
    }

    /**
     * A portal world with monitoring ON: one active {@see CustomerSystem} that
     * has a seeded uptime rollup day and an OPEN Outage incident (→ status
     * "Störung"). The system also carries secret fields that must NOT leak.
     *
     * @return array{portalUser: User}
     */
    private function seedMonitoring(): array
    {
        $ws = (new Workspace())
            ->setName('Mon WS')
            ->setSlug('mon-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'monitoring' => true]]]);
        $this->em->persist($ws);

        $portalUser = $this->user('portal.mon@example.test', ['ROLE_PORTAL']);

        $customer = $this->customer($ws, 'Mon GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Mona')->setLastName('Monitor')
            ->setEmail('portal.mon@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        // An external project so the customer resolves through allowedProjects().
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);
        $this->project($ws, $customer, 'MON', $projectStatus);

        $system = (new CustomerSystem())
            ->setCustomer($customer)
            ->setName('shop.mon.test')
            ->setType(SystemType::Shopware)
            ->setEnvironment(SystemEnvironment::Production)
            ->setUrl('https://shop.mon.test')
            ->setIsActive(true)
            ->setCredentialsNotes('SECRET admin:hunter2')
            ->setNotes('internal ops note')
            ->setAdminLoginUrl('https://shop.mon.test/admin');
        $this->em->persist($system);

        $day = (new SystemUptimeDay())->setSystem($system)->setDay(new \DateTimeImmutable('today'))
            ->setUptimePct(95.0)->setAvgResponseMs(420)->setSampleCount(288);
        $this->em->persist($day);

        $incident = (new SystemIncident())->setSystem($system)->setKind(IncidentKind::Outage)
            ->setTitle('Shop nicht erreichbar')->setStartedAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($incident);

        $this->em->flush();
        $this->em->clear();

        return ['portalUser' => $portalUser];
    }

    /**
     * A portal world with the dashboard feature ON: a retainer project with a
     * 600-min budget + 300 tracked minutes this month, and two tickets wired
     * predecessor→successor so the successor is BLOCKED.
     *
     * @return array{portalUser: User}
     */
    private function seedDashboard(): array
    {
        $ws = (new Workspace())
            ->setName('Dash WS')
            ->setSlug('dash-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'dashboard' => true]]]);
        $this->em->persist($ws);

        $status = (new TaskStatus())->setWorkspace($ws)->setName('Offen')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsDefault(true);
        $this->em->persist($status);
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);

        $portalUser = $this->user('portal.dash@example.test', ['ROLE_PORTAL']);
        $staff = $this->user('portal.dashstaff@example.test', []);

        $customer = $this->customer($ws, 'Dash GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Dora')->setLastName('Dash')
            ->setEmail('portal.dash@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);

        $project = $this->project($ws, $customer, 'DASH', $projectStatus);
        $project->setIsRetainer(true)->setBudgetMinutes(600);

        $predecessor = $this->task($ws, $project, $status, 'DASH-1', false);
        $blocked = $this->task($ws, $project, $status, 'DASH-2', false);
        $this->em->persist(
            (new TaskDependency())->setWorkspace($ws)->setPredecessor($predecessor)->setSuccessor($blocked)->setType(TaskDependencyType::Blocks),
        );

        $entry = (new TimeEntry())->setWorkspace($ws)->setUser($staff)->setProject($project)
            ->setStartsAt(new \DateTimeImmutable('first day of this month 09:00'))
            ->setDurationMinutes(300)->setIsBillable(true)->setIsBilled(false)->setIsExternal(false);
        $this->em->persist($entry);

        $this->em->flush();
        $this->em->clear();

        return ['portalUser' => $portalUser];
    }

    /**
     * A portal world with the agreements feature ON: one signed agreement whose
     * in-force revision carries two recurring line items (1×10000 + 2×5000).
     *
     * @return array{portalUser: User}
     */
    private function seedAgreements(): array
    {
        $ws = (new Workspace())
            ->setName('Agr WS')
            ->setSlug('agr-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'agreements' => true]]]);
        $this->em->persist($ws);

        $portalUser = $this->user('portal.agr@example.test', ['ROLE_PORTAL']);

        $customer = $this->customer($ws, 'Agr GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Alan')->setLastName('Agr')
            ->setEmail('portal.agr@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);
        $this->project($ws, $customer, 'AGR', $projectStatus);

        $type = (new AgreementType())->setName('Website-Wartung')->setSlug('wartung');
        $type->setWorkspace($ws);
        $this->em->persist($type);

        $agreement = (new CustomerAgreement())->setCustomer($customer)->setType($type)->setStatus(AgreementStatus::Signed);
        $this->em->persist($agreement);

        $revision = (new CustomerAgreementRevision())->setAgreement($agreement)->setVersionNo(1)
            ->setStatus(AgreementStatus::Signed)->setReference('A-2026-999');
        $revision
            ->addLineItem((new AgreementLineItem())->setDescription('Wartung')->setQuantity(1)->setUnitAmountCents(10000)->setIsRecurring(true)->setPosition(0))
            ->addLineItem((new AgreementLineItem())->setDescription('Support 2 Std.')->setQuantity(2)->setUnitAmountCents(5000)->setIsRecurring(true)->setPosition(1));
        $this->em->persist($revision);
        $agreement->setCurrentRevision($revision);

        $this->em->flush();
        $this->em->clear();

        return ['portalUser' => $portalUser];
    }

    /**
     * A portal world with the ideas feature ON and one seeded agency
     * brainstorming note.
     *
     * @return array{portalUser: User}
     */
    private function seedBrainstorm(): array
    {
        $ws = (new Workspace())
            ->setName('Brain WS')
            ->setSlug('brain-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'ideas' => true]]]);
        $this->em->persist($ws);

        $portalUser = $this->user('portal.brain@example.test', ['ROLE_PORTAL']);

        $customer = $this->customer($ws, 'Brain GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Alan')->setLastName('Agr')
            ->setEmail('portal.brain@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);
        $this->project($ws, $customer, 'BRN', $projectStatus);

        $this->em->persist(
            (new BrainstormNote())->setCustomer($customer)->setBody('Vorschlag der Agentur')
                ->setOrigin(IdeaOrigin::Agency)->setAuthorName('Lena (Agentur)'),
        );

        $this->em->flush();
        $this->em->clear();

        return ['portalUser' => $portalUser];
    }

    /**
     * A portal world with the invoices feature ON and two mirrored invoices:
     * a paid one (older) and an open+past-due one (newer → derived overdue).
     *
     * @return array{portalUser: User}
     */
    private function seedInvoices(): array
    {
        $ws = (new Workspace())
            ->setName('Inv WS')
            ->setSlug('inv-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'invoices' => true]]]);
        $this->em->persist($ws);

        $portalUser = $this->user('portal.inv@example.test', ['ROLE_PORTAL']);
        $customer = $this->customer($ws, 'Inv GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Ida')->setLastName('Invoice')
            ->setEmail('portal.inv@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);
        $this->project($ws, $customer, 'INV', $projectStatus);

        $this->em->persist(
            (new Invoice())->setCustomer($customer)->setLexofficeId('lx-t-1')->setNumber('RE-1')
                ->setIssuedOn(new \DateTimeImmutable('-30 days'))->setDueOn(new \DateTimeImmutable('-16 days'))
                ->setTotalCents(10000)->setOpenCents(0)->setStatus(InvoiceStatus::Paid),
        );
        $this->em->persist(
            (new Invoice())->setCustomer($customer)->setLexofficeId('lx-t-2')->setNumber('RE-2')
                ->setIssuedOn(new \DateTimeImmutable('-20 days'))->setDueOn(new \DateTimeImmutable('-6 days'))
                ->setTotalCents(5000)->setOpenCents(5000)->setStatus(InvoiceStatus::Open),
        );

        $this->em->flush();
        $this->em->clear();

        return ['portalUser' => $portalUser];
    }

    /**
     * A portal world with the agreements feature ON and one OPEN offer
     * (InNegotiation + a revision → signable) for the Rückfrage test.
     *
     * @return array{portalUser: User, agreementId: string}
     */
    private function seedInquirableAgreement(): array
    {
        $ws = (new Workspace())
            ->setName('Inq WS')
            ->setSlug('inq-ws-' . substr(Uuid::v7()->toRfc4122(), 0, 8))
            ->setLocale('de')
            ->setTimezone('Europe/Berlin')
            ->setSettings(['portal' => ['enabled' => true, 'features' => ['tickets' => true, 'agreements' => true]]]);
        $this->em->persist($ws);

        $portalUser = $this->user('portal.inq@example.test', ['ROLE_PORTAL']);
        $customer = $this->customer($ws, 'Inq GmbH');
        $contact = (new Contact())->setCustomer($customer)->setFirstName('Ina')->setLastName('Inq')
            ->setEmail('portal.inq@example.test')->setLinkedUser($portalUser);
        $this->em->persist($contact);
        $projectStatus = (new ProjectStatus())->setWorkspace($ws)->setName('Aktiv')->setColor('#888')->setPosition(0)->setIsCompleted(false)->setIsArchived(false);
        $this->em->persist($projectStatus);
        $this->project($ws, $customer, 'INQ', $projectStatus);

        $type = (new AgreementType())->setName('Angebot')->setSlug('angebot');
        $type->setWorkspace($ws);
        $this->em->persist($type);

        $agreement = (new CustomerAgreement())->setCustomer($customer)->setType($type)->setStatus(AgreementStatus::InNegotiation);
        $this->em->persist($agreement);
        $revision = (new CustomerAgreementRevision())->setAgreement($agreement)->setVersionNo(1)
            ->setStatus(AgreementStatus::InNegotiation)->setReference('A-9');
        $this->em->persist($revision);
        $agreement->setCurrentRevision($revision);

        $this->em->flush();
        $agreementId = $agreement->getId()?->toRfc4122() ?? '';
        $this->em->clear();

        return ['portalUser' => $portalUser, 'agreementId' => $agreementId];
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles): User
    {
        $user = (new User())->setEmail($email)->setFirstName('T')->setLastName('U')->setRoles($roles);
        $user->setPassword('noop'); // JWT auth never checks it in these tests
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

    private function project(Workspace $ws, Customer $customer, string $key, ProjectStatus $status): Project
    {
        $project = (new Project())->setWorkspace($ws)->setCustomer($customer)->setName($key . ' Projekt')
            ->setKey($key)->setColor('#000000')->setStatus($status)->setIsExternal(true);
        $this->em->persist($project);
        return $project;
    }

    private function task(Workspace $ws, Project $project, TaskStatus $status, string $identifier, bool $hidden): Task
    {
        $task = (new Task())->setWorkspace($ws)->setProject($project)->setIdentifier($identifier)
            ->setTitle($identifier)->setStatus($status)->setPriority(TaskPriority::Normal)->setIsHiddenForConnectUsers($hidden);
        $this->em->persist($task);
        return $task;
    }
}
