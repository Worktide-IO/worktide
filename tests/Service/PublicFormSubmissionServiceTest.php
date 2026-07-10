<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Enum\TaskCreatedVia;
use App\Entity\Enum\TaskPriority;
use App\Entity\Project;
use App\Entity\PublicForm;
use App\Entity\Task;
use App\Entity\TaskStatus;
use App\Entity\Workspace;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\PublicFormRepository;
use App\Repository\TaskStatusRepository;
use App\Service\Form\FormLogicEvaluator;
use App\Service\Form\FormSchemaNormalizer;
use App\Service\PublicFormSubmissionClosedException;
use App\Service\PublicFormSubmissionService;
use App\Service\PublicFormValidationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for public-form submission → task materialization.
 *
 * No database — repositories are stubbed and the EntityManager is a mock that
 * captures persisted entities and stamps a UUID on the Task (standing in for
 * the CUSTOM UuidGenerator), mirroring {@see PasswordResetServiceTest}.
 */
final class PublicFormSubmissionServiceTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testValidSubmissionCreatesTaskWithMappedFieldsAndCustomFieldValue(): void
    {
        $cfDef = new CustomFieldDefinition();
        $customFields = $this->createStub(CustomFieldDefinitionRepository::class);
        $customFields->method('findOneBy')->willReturn($cfDef);

        $form = $this->form([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'mapsTo' => 'title'],
            ['key' => 'msg', 'label' => 'Message', 'type' => 'long_text', 'mapsTo' => 'description'],
            ['key' => 'urgency', 'label' => 'Urgency', 'type' => 'select', 'options' => ['low', 'high'], 'mapsTo' => 'priority'],
            ['key' => 'budget', 'label' => 'Budget', 'type' => 'number', 'mapsTo' => 'cf:budget'],
        ]);

        $submission = $this->service($customFields)->submit($form, [
            'name' => 'Ada Lovelace',
            'msg' => 'Please help',
            'urgency' => 'high',
            'budget' => '1500',
            '_hp' => '', // honeypot empty — handled by controller, ignored here
        ], '203.0.113.9', 'curl/8.0');

        $task = $this->firstOf(Task::class);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame('Ada Lovelace', $task->getTitle());
        self::assertSame('Please help', $task->getDescription());
        self::assertSame(TaskPriority::High, $task->getPriority());
        self::assertSame(TaskCreatedVia::Form, $task->getCreatedVia());
        self::assertStringStartsWith('PUB-', $task->getIdentifier());
        self::assertNull($task->getCreatedByUser(), 'public submissions have no authenticated author');

        $cfv = $this->firstOf(CustomFieldValue::class);
        self::assertInstanceOf(CustomFieldValue::class, $cfv);
        self::assertSame(1500, $cfv->getValue());
        self::assertSame($cfDef, $cfv->getDefinition());

        self::assertSame($task, $submission->getCreatedTask());
        self::assertSame('203.0.113.9', $submission->getRemoteIp());
        // The submission slot is claimed atomically at the DB level
        // (PublicFormRepository::tryClaimSubmissionSlot), not by mutating the
        // in-memory entity — see testSubmissionAtLimitThrowsClosedException.
        // Reserved honeypot key must not leak into the audit payload.
        self::assertArrayNotHasKey('_hp', $submission->getPayload());
        self::assertSame('Ada Lovelace', $submission->getPayload()['name']);
    }

    public function testSubmissionAtLimitThrowsClosedException(): void
    {
        $form = $this->form([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'mapsTo' => 'title'],
        ]);

        // Slot claim fails (form full / lost the concurrent race) → closed, 409.
        $this->expectException(PublicFormSubmissionClosedException::class);
        $this->service(claimSlot: false)->submit($form, ['name' => 'Ada']);
    }

    public function testMissingRequiredFieldThrowsValidationException(): void
    {
        $form = $this->form([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'mapsTo' => 'title'],
        ]);

        try {
            $this->service()->submit($form, []);
            self::fail('expected PublicFormValidationException');
        } catch (PublicFormValidationException $e) {
            self::assertArrayHasKey('name', $e->getErrors());
        }

        self::assertSame([], $this->persisted, 'nothing persisted on validation failure');
        self::assertSame(0, $form->getSubmissionCount());
    }

    public function testInvalidSelectValueIsRejected(): void
    {
        $form = $this->form([
            ['key' => 'urgency', 'label' => 'Urgency', 'type' => 'select', 'options' => ['low', 'high'], 'required' => true, 'mapsTo' => 'priority'],
        ]);

        $this->expectException(PublicFormValidationException::class);
        $this->service()->submit($form, ['urgency' => 'nope']);
    }

    public function testUnknownCustomFieldKeyIsSkippedButTaskStillCreated(): void
    {
        $customFields = $this->createStub(CustomFieldDefinitionRepository::class);
        $customFields->method('findOneBy')->willReturn(null); // no such definition

        $form = $this->form([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'mapsTo' => 'title'],
            ['key' => 'ghost', 'label' => 'Ghost', 'type' => 'text', 'mapsTo' => 'cf:ghost'],
        ]);

        $this->service($customFields)->submit($form, ['name' => 'X', 'ghost' => 'unmapped']);

        self::assertInstanceOf(Task::class, $this->firstOf(Task::class));
        self::assertNull($this->firstOf(CustomFieldValue::class), 'no CFV for an unknown key');
    }

    public function testStatusFallsBackToWorkspaceDefaultWhenFormHasNone(): void
    {
        $defaultStatus = new TaskStatus();
        $statuses = $this->createMock(TaskStatusRepository::class);
        $statuses->expects(self::once())
            ->method('findOneBy')
            ->with(self::callback(static fn (array $c): bool => ($c['isDefault'] ?? null) === true))
            ->willReturn($defaultStatus);

        $form = $this->form([
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'mapsTo' => 'title'],
        ], withDefaultStatus: false);

        $this->service(taskStatuses: $statuses)->submit($form, ['name' => 'X']);

        $task = $this->firstOf(Task::class);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame($defaultStatus, $task->getStatus());
    }

    // --- engine (schema v2) -------------------------------------------

    public function testBranchingDeactivatedRequiredFieldIsNotEnforced(): void
    {
        $form = $this->formV2([
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'ba', 'key' => 'has_site', 'type' => 'select', 'options' => ['yes', 'no']],
                    ['id' => 'bx', 'key' => 'site_url', 'type' => 'url', 'required' => true],
                ],
            ]],
            'logic' => [
                ['if' => ['all' => [['field' => 'has_site', 'op' => 'eq', 'value' => 'yes']]],
                    'then' => ['action' => 'show', 'target' => 'bx']],
            ],
        ]);

        // has_site=no → site_url is hidden by branching, so its absence must NOT
        // fail validation and a smuggled value must be dropped.
        $submission = $this->service()->submit($form, ['has_site' => 'no', 'site_url' => 'not-a-url']);

        self::assertInstanceOf(Task::class, $this->firstOf(Task::class));
        self::assertArrayNotHasKey('site_url', $submission->getPayload(), 'deactivated field is discarded');
    }

    public function testBranchingActiveRequiredFieldIsEnforced(): void
    {
        $form = $this->formV2([
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'ba', 'key' => 'has_site', 'type' => 'select', 'options' => ['yes', 'no']],
                    ['id' => 'bx', 'key' => 'site_url', 'type' => 'url', 'required' => true],
                ],
            ]],
            'logic' => [
                ['if' => ['all' => [['field' => 'has_site', 'op' => 'eq', 'value' => 'yes']]],
                    'then' => ['action' => 'show', 'target' => 'bx']],
            ],
        ]);

        $this->expectException(PublicFormValidationException::class);
        $this->service()->submit($form, ['has_site' => 'yes']); // site_url now required
    }

    public function testCalcIsComputedServerSideAndClientValueIgnored(): void
    {
        $cfDef = new CustomFieldDefinition();
        $customFields = $this->createStub(CustomFieldDefinitionRepository::class);
        $customFields->method('findOneBy')->willReturn($cfDef);

        $form = $this->formV2([
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'b1', 'key' => 'pages', 'type' => 'number'],
                    ['id' => 'b2', 'key' => 'rate', 'type' => 'number'],
                ],
            ]],
            'calc' => [
                ['key' => 'total', 'mapsTo' => 'cf:total', 'ast' => ['op' => '*', 'args' => [['field' => 'pages'], ['field' => 'rate']]]],
            ],
        ]);

        $submission = $this->service($customFields)->submit($form, [
            'pages' => '10',
            'rate' => '25',
            'total' => '999999', // client-supplied — must be ignored
        ]);

        self::assertSame(250, $submission->getPayload()['total']);
        $cfv = $this->firstOf(CustomFieldValue::class);
        self::assertInstanceOf(CustomFieldValue::class, $cfv);
        self::assertSame(250, $cfv->getValue());
    }

    public function testHiddenPrefillFieldUsesServerValueNotClientValue(): void
    {
        $cfDef = new CustomFieldDefinition();
        $customFields = $this->createStub(CustomFieldDefinitionRepository::class);
        $customFields->method('findOneBy')->willReturn($cfDef);

        $form = $this->formV2([
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'b1', 'key' => 'name', 'type' => 'text', 'required' => true, 'mapsTo' => 'title'],
                    ['id' => 'b2', 'key' => 'cid', 'type' => 'text', 'hidden' => true, 'prefillFrom' => 'contact.id', 'mapsTo' => 'cf:contact'],
                ],
            ]],
        ]);

        $submission = $this->service($customFields)->submit(
            $form,
            ['name' => 'Ada', 'cid' => 'attacker-supplied'],
            null,
            null,
            ['cid' => 'server-contact-uuid'],
        );

        self::assertSame('server-contact-uuid', $submission->getPayload()['cid']);
        $cfv = $this->firstOf(CustomFieldValue::class);
        self::assertSame('server-contact-uuid', $cfv?->getValue());
    }

    public function testNewFieldTypesAreCoerced(): void
    {
        $form = $this->formV2([
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'b1', 'key' => 'title', 'type' => 'text', 'required' => true, 'mapsTo' => 'title'],
                    ['id' => 'b2', 'key' => 'channels', 'type' => 'multi_select', 'options' => ['seo', 'ads', 'social']],
                    ['id' => 'b3', 'key' => 'nps', 'type' => 'rating', 'min' => 1, 'max' => 5],
                ],
            ]],
        ]);

        $submission = $this->service()->submit($form, [
            'title' => 'Audit',
            'channels' => ['seo', 'social'],
            'nps' => '4',
        ]);

        self::assertSame(4, $submission->getPayload()['nps']);
        // Arrays are JSON-encoded in the audit payload.
        self::assertSame(['seo', 'social'], json_decode((string) $submission->getPayload()['channels'], true));
    }

    public function testMultiSelectRejectsValueOutsideOptions(): void
    {
        $form = $this->formV2([
            'pages' => [[
                'id' => 'p1',
                'blocks' => [
                    ['id' => 'b1', 'key' => 'title', 'type' => 'text', 'required' => true, 'mapsTo' => 'title'],
                    ['id' => 'b2', 'key' => 'channels', 'type' => 'multi_select', 'options' => ['seo', 'ads']],
                ],
            ]],
        ]);

        $this->expectException(PublicFormValidationException::class);
        $this->service()->submit($form, ['title' => 'X', 'channels' => ['seo', 'tv']]);
    }

    // --- helpers -------------------------------------------------------

    /**
     * @param array<string, mixed> $schema
     */
    private function formV2(array $schema): PublicForm
    {
        return (new PublicForm())
            ->setWorkspace(new Workspace())
            ->setProject((new Project())->setKey('pub'))
            ->setSlug('audit')
            ->setTitle('Audit')
            ->setFields([])
            ->setSchema($schema)
            ->setSchemaVersion(2)
            ->setDefaultStatus(new TaskStatus());
    }

    /**
     * @param list<array<string, mixed>> $fields
     */
    private function form(array $fields, bool $withDefaultStatus = true): PublicForm
    {
        $project = (new Project())->setKey('pub');
        $form = (new PublicForm())
            ->setWorkspace(new Workspace())
            ->setProject($project)
            ->setSlug('contact')
            ->setTitle('Contact us')
            ->setFields($fields);
        if ($withDefaultStatus) {
            $form->setDefaultStatus(new TaskStatus());
        }

        return $form;
    }

    private function service(
        ?CustomFieldDefinitionRepository $customFields = null,
        ?TaskStatusRepository $taskStatuses = null,
        bool $claimSlot = true,
    ): PublicFormSubmissionService {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            // Stand in for the CUSTOM UuidGenerator: Task ids are available
            // right after persist(), which the service relies on for the CFV.
            if ($entity instanceof Task && $entity->getId() === null) {
                $ref = new \ReflectionProperty($entity, 'id');
                $ref->setValue($entity, Uuid::v7());
            }
            $this->persisted[] = $entity;
        });

        $forms = $this->createStub(PublicFormRepository::class);
        $forms->method('tryClaimSubmissionSlot')->willReturn($claimSlot);

        return new PublicFormSubmissionService(
            $em,
            $taskStatuses ?? $this->createStub(TaskStatusRepository::class),
            $customFields ?? $this->createStub(CustomFieldDefinitionRepository::class),
            new FormSchemaNormalizer(),
            new FormLogicEvaluator(),
            $forms,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function firstOf(string $class): ?object
    {
        foreach ($this->persisted as $entity) {
            if ($entity instanceof $class) {
                return $entity;
            }
        }

        return null;
    }
}
