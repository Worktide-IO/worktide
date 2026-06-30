<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Channels\SyncReentryGuard;
use App\Entity\Channel;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Enum\CustomFieldTarget;
use App\Entity\Enum\CustomFieldType;
use App\Entity\Enum\SyncMode;
use App\Entity\EntitySync;
use App\Entity\Project;
use App\Entity\ProjectStatus;
use App\Entity\Workspace;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use App\Repository\EntitySyncRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Persists the Redmine-project ↔ worktide-project reconciliation as the source
 * of truth, so the importer can route issues by it.
 *
 * The mapping lives in {@see EntitySync} with `entityType='project'` and
 * `syncMode=Inbound` (a project is a reference here — we never push projects
 * back, and Inbound keeps the change-outbox from trying). This is multi-source
 * safe: a project already carrying an awork {@see \App\Entity\Trait\ExternalReferenceTrait}
 * reference can hold a Redmine mapping here in parallel. The external id is also
 * mirrored into a visible "Redmine-Projekt-ID" custom field on the project.
 *
 * {@see resolveLocalProject()} is the read side the seed importer calls.
 */
final class ProjectMappingService
{
    private const FIELD_KEY = 'redmine_project_id';
    private const FIELD_LABEL = 'Redmine-Projekt-ID';
    private const ENTITY_TYPE = 'project';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntitySyncRepository $syncs,
        private readonly ProjectRepository $projects,
        private readonly ProjectStatusRepository $projectStatuses,
        private readonly CustomFieldDefinitionRepository $fieldDefs,
        private readonly CustomFieldValueRepository $fieldValues,
        private readonly SyncReentryGuard $guard,
    ) {}

    /**
     * Resolve the local project a Redmine project id maps to (read side for the
     * importer), or null if unmapped.
     */
    public function resolveLocalProject(Channel $channel, string $externalProjectId): ?Project
    {
        $sync = $this->syncs->findOneBy([
            'channel' => $channel,
            'entityType' => self::ENTITY_TYPE,
            'externalId' => $externalProjectId,
        ]);

        return $sync !== null ? $this->projects->find($sync->getEntityId()) : null;
    }

    /**
     * Apply a reviewed mapping. Each entry: {redmineProjectId, redmineName,
     * redmineIdentifier, target: <projectUuid>|"new"|"skip"}.
     *
     * @param list<array<string, mixed>> $entries
     * @return array{linked: int, created: int, skipped: int}
     */
    public function applyMap(Channel $channel, array $entries, string $baseUrl, bool $dryRun = false): array
    {
        $workspace = $channel->getWorkspace();
        $linked = 0;
        $created = 0;
        $skipped = 0;

        $run = function () use ($entries, $channel, $workspace, $baseUrl, $dryRun, &$linked, &$created, &$skipped): void {
            $definition = $this->ensureRedmineIdField($workspace, $dryRun);

            foreach ($entries as $e) {
                $externalId = (string) ($e['redmineProjectId'] ?? '');
                $target = (string) ($e['target'] ?? 'skip');
                if ($externalId === '' || $target === 'skip') {
                    ++$skipped;
                    continue;
                }

                // Idempotent: already mapped?
                $existing = $this->syncs->findOneBy([
                    'channel' => $channel,
                    'entityType' => self::ENTITY_TYPE,
                    'externalId' => $externalId,
                ]);
                if ($existing !== null) {
                    ++$skipped;
                    continue;
                }

                if ($target === 'new') {
                    $project = $this->createProject($workspace, (string) ($e['redmineName'] ?? 'Redmine'), (string) ($e['redmineIdentifier'] ?? ''), $externalId, $dryRun);
                    ++$created;
                } else {
                    $project = $this->resolveTarget($target, $workspace);
                    if ($project === null) {
                        ++$skipped;
                        continue;
                    }
                    ++$linked;
                }

                if (!$dryRun) {
                    $this->em->persist(
                        (new EntitySync())
                            ->setWorkspace($workspace)
                            ->setChannel($channel)
                            ->setEntityType(self::ENTITY_TYPE)
                            ->setEntityId($project->getId())
                            ->setExternalId($externalId)
                            ->setExternalUrl($this->projectUrl($baseUrl, (string) ($e['redmineIdentifier'] ?? '')))
                            ->setSyncMode(SyncMode::Inbound),
                    );
                    $this->mirrorCustomField($definition, $project, $externalId);
                }
            }

            if (!$dryRun) {
                $this->em->flush();
            }
        };

        // Guard so creating mappings/projects doesn't enqueue an outbound push.
        $dryRun ? $run() : $this->guard->run($run);

        return ['linked' => $linked, 'created' => $created, 'skipped' => $skipped];
    }

    public function ensureRedmineIdField(Workspace $workspace, bool $dryRun = false): ?CustomFieldDefinition
    {
        $def = $this->fieldDefs->findOneBy([
            'workspace' => $workspace,
            'target' => CustomFieldTarget::Project,
            'key' => self::FIELD_KEY,
        ]);
        if ($def !== null || $dryRun) {
            return $def;
        }

        $def = (new CustomFieldDefinition())
            ->setWorkspace($workspace)
            ->setTarget(CustomFieldTarget::Project)
            ->setType(CustomFieldType::Text)
            ->setKey(self::FIELD_KEY)
            ->setLabel(self::FIELD_LABEL)
            ->setDescription('Externe Redmine-Projekt-ID (Sync-Referenz).');
        $this->em->persist($def);

        return $def;
    }

    private function resolveTarget(string $uuid, Workspace $workspace): ?Project
    {
        try {
            $project = $this->projects->find(Uuid::fromString($uuid));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $project !== null && $project->getWorkspace() === $workspace ? $project : null;
    }

    private function createProject(Workspace $workspace, string $name, string $identifier, string $externalId, bool $dryRun): Project
    {
        $project = (new Project())
            ->setWorkspace($workspace)
            ->setName($name !== '' ? $name : ('Redmine ' . $externalId))
            ->setKey($this->uniqueKey($workspace, $identifier !== '' ? $identifier : $name))
            ->setExternalSource('redmine')
            ->setExternalId($externalId);

        $project->setStatus($this->pickDefaultStatus($workspace));

        if (!$dryRun) {
            $this->em->persist($project);
        }

        return $project;
    }

    private function uniqueKey(Workspace $workspace, string $seed): string
    {
        $base = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $seed));
        $base = $base !== '' ? substr($base, 0, 10) : 'PROJ';
        $key = $base;
        $i = 1;
        while ($this->projects->findOneBy(['workspace' => $workspace, 'key' => $key]) !== null) {
            $suffix = (string) $i++;
            $key = substr($base, 0, 16 - \strlen($suffix)) . $suffix;
        }

        return $key;
    }

    /**
     * Pick a sensible default status for an imported project. Imported Redmine
     * projects are ongoing, so we want an active/running status — never a
     * terminal one like "Abgebrochen" (the old code grabbed an arbitrary
     * findOneBy(), which happened to be that). Preference order:
     *   1. a status whose name reads as "running/active",
     *   2. otherwise the lowest-position status that is neither completed nor
     *      archived,
     *   3. otherwise the first status that exists.
     */
    private function pickDefaultStatus(Workspace $workspace): ProjectStatus
    {
        /** @var list<ProjectStatus> $statuses */
        $statuses = $this->projectStatuses->findBy(['workspace' => $workspace], ['position' => 'ASC']);
        if ($statuses === []) {
            throw new \RuntimeException('Workspace has no ProjectStatus; cannot create projects.');
        }

        foreach ($statuses as $status) {
            if (preg_match('/läuft|laufend|aktiv|active|running|in bearbeitung|in progress|offen/i', $status->getName())) {
                return $status;
            }
        }
        foreach ($statuses as $status) {
            if (!$status->isCompleted() && !$status->isArchived()) {
                return $status;
            }
        }

        return $statuses[0];
    }

    private function mirrorCustomField(?CustomFieldDefinition $definition, Project $project, string $externalId): void
    {
        if ($definition === null) {
            return;
        }
        $value = $this->fieldValues->findOneBy(['definition' => $definition, 'targetId' => $project->getId()])
            ?? (new CustomFieldValue())
                ->setWorkspace($project->getWorkspace())
                ->setDefinition($definition)
                ->setTarget(CustomFieldTarget::Project)
                ->setTargetId($project->getId());
        $value->setValue($externalId);
        $this->em->persist($value);
    }

    private function projectUrl(string $baseUrl, string $identifier): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');
        return ($baseUrl !== '' && $identifier !== '') ? $baseUrl . '/projects/' . $identifier : null;
    }
}
