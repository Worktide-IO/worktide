<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use ApiPlatform\Metadata\ApiResource;
use App\Entity\Trait\WorkspaceScopedTrait;
use PHPUnit\Framework\TestCase;

/**
 * Phase-T fail-closed guardrail: EVERY API Platform resource must be
 * tenant-isolated. Concretely, each `#[ApiResource]` entity must either
 *
 *   (a) carry {@see WorkspaceScopedTrait} — so {@see \App\ApiPlatform\Doctrine\WorkspaceScopeExtension}
 *       filters every collection/item query to the caller's workspaces, or
 *   (b) appear in {@see self::EXEMPT} with a written reason (a resource the
 *       scope-extension handles bespoke, or an intentionally public one).
 *
 * A NEW ApiResource that is neither trait-scoped nor listed here fails this
 * test → the cross-tenant-leak class of bug (#48) breaks the build instead of
 * shipping. When this test goes red for a new resource, the fix is a decision,
 * not a rubber-stamp: add the trait (the safe default) OR add an EXEMPT entry
 * justifying why the resource is safe without it.
 *
 * Pure reflection over src/Entity — no kernel, fast, runs in every suite.
 */
final class ApiResourceScopingTest extends TestCase
{
    /**
     * Resources that are ApiResources but deliberately NOT WorkspaceScopedTrait.
     * The value is the reason (kept in sync with WorkspaceScopeExtension::apply()
     * and config/packages/security.yaml). Two buckets:
     *
     *  - BESPOKE: the scope-extension scopes them by a hand-written branch
     *    (they have no `.workspace` association to key off the generic trait).
     *  - PUBLIC: reached unauthenticated via a slug/token credential
     *    (is_granted('PUBLIC_ACCESS')); tenant is derived from the credential,
     *    not the caller — so workspace-membership scoping does not apply.
     *
     * @var array<class-string, string>
     */
    private const EXEMPT = [
        // --- BESPOKE scope-extension branches (WorkspaceScopeExtension.php:83-96) ---
        \App\Entity\Workspace::class => 'BESPOKE: scoped to workspaces the caller is a member of.',
        \App\Entity\User::class => 'BESPOKE: scoped by co-membership (shares a workspace with the caller).',
        \App\Entity\WorkspaceMember::class => 'BESPOKE: scoped via the membership row itself.',
        \App\Entity\UserContactInfo::class => 'BESPOKE: per-user PII, self-only.',
        \App\Entity\UserCapacity::class => 'BESPOKE: per-user capacity, self-only.',
        \App\Entity\ProjectMember::class => 'BESPOKE: scoped via the parent project\'s workspace.',
        \App\Entity\DomainEventLog::class => 'BESPOKE: read-only audit log, scoped via its (nullable) .workspace.',
        // Parent-scoped children (WorkspaceScopeExtension::PARENT_SCOPED). No
        // workspace column of their own; the collection is scoped via the
        // parent's workspace. Surfaced as leaks by this test's first run, then
        // fixed — WebhookDelivery was verified empirically (an A-member's
        // GET /v1/webhook_deliveries had leaked a B-tenant delivery).
        \App\Entity\WebhookDelivery::class => 'BESPOKE: scoped via parent Webhook.workspace.',
        \App\Entity\AutomationAction::class => 'BESPOKE: scoped via parent Automation.workspace.',
        \App\Entity\DocumentContributor::class => 'BESPOKE: scoped via parent Document.workspace.',
        \App\Entity\TaskListEntry::class => 'BESPOKE: scoped via parent TaskList.workspace.',
        \App\Entity\ProductShare::class => 'BESPOKE: scoped via getWorkspace() → targetWorkspace (cross-workspace sharing). Product queries are additionally scoped via WorkspaceScopeExtension ProductShare EXISTS subquery.',
    ];

    public function testEveryApiResourceIsWorkspaceScopedOrExplicitlyExempt(): void
    {
        $offenders = [];

        foreach ($this->apiResourceEntities() as $class) {
            if ($this->usesWorkspaceScopedTrait($class)) {
                continue; // (a) generic scoping applies.
            }
            if (\array_key_exists($class, self::EXEMPT)) {
                continue; // (b) documented exemption (bespoke scoping or public credential).
            }
            $offenders[] = $class;
        }

        self::assertSame(
            [],
            $offenders,
            "These #[ApiResource] entities are neither workspace-scoped nor documented-exempt — "
            . "they risk leaking data across tenants. Add WorkspaceScopedTrait (the safe default) or, "
            . "if genuinely public/bespoke, add an entry to ApiResourceScopingTest::EXEMPT with a reason:\n  - "
            . implode("\n  - ", $offenders),
        );
    }

    /**
     * Guard against rot: every EXEMPT entry must still be a real ApiResource
     * that still lacks the trait. If a class was deleted or later gained the
     * trait, drop it from EXEMPT so the list keeps meaning something.
     */
    public function testExemptListHasNoStaleEntries(): void
    {
        $stale = [];
        foreach (self::EXEMPT as $class => $_reason) {
            if (!class_exists($class) || $this->usesWorkspaceScopedTrait($class) || !$this->isApiResource($class)) {
                $stale[] = $class;
            }
        }

        self::assertSame(
            [],
            $stale,
            "Stale EXEMPT entries (deleted, no longer an ApiResource, or now trait-scoped) — remove them:\n  - "
            . implode("\n  - ", $stale),
        );
    }

    /**
     * All concrete `App\Entity\*` classes carrying `#[ApiResource]`.
     *
     * @return list<class-string>
     */
    private function apiResourceEntities(): array
    {
        $dir = \dirname(__DIR__, 2) . '/src/Entity';
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($dir) + 1, -4); // strip dir + ".php"
            $class = 'App\\Entity\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue; // traits, interfaces, enums → not classes we can scope.
            }
            if ($this->isApiResource($class)) {
                $found[] = $class;
            }
        }

        sort($found);
        // Sanity: the scan must find the well-known resources, otherwise a path
        // change silently voided this guard.
        self::assertContains(\App\Entity\Customer::class, $found, 'ApiResource scan found no Customer — the guard is not actually scanning src/Entity.');

        return $found;
    }

    /** @param class-string $class */
    private function isApiResource(string $class): bool
    {
        return (new \ReflectionClass($class))->getAttributes(ApiResource::class) !== [];
    }

    /** Walk the trait use-chain (incl. parents) exactly like WorkspaceScopeExtension::isWorkspaceScoped(). */
    private function usesWorkspaceScopedTrait(string $class): bool
    {
        $traits = [];
        $cursor = $class;
        while ($cursor !== false) {
            $traits += class_uses($cursor) ?: [];
            $cursor = get_parent_class($cursor);
        }

        return isset($traits[WorkspaceScopedTrait::class]);
    }
}
