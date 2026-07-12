<?php

declare(strict_types=1);

namespace App\Tests\Service\PromptInjection;

use App\Entity\Project;
use App\Service\Portal\PortalTicketSuggester;
use App\Tests\Support\PromptInjectionPayloads;
use App\Tests\Support\RecordingLlmProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Prompt-injection resilience for the customer-facing ticket suggester. The
 * `$description` is fully attacker-controlled (any portal customer types it), so
 * it is the canonical untrusted-input vector.
 *
 * Two guarantees:
 *  1. hygiene — injected instructions ride in the user message only, never the
 *     system prompt;
 *  2. output guardrail — even a fully hijacked model cannot make the suggester
 *     assign a project the customer isn't allowed to see (cross-tenant), nor
 *     forge a priority outside the enum.
 */
final class PortalTicketSuggesterInjectionTest extends TestCase
{
    public function testInjectedDescriptionNeverReachesTheSystemPrompt(): void
    {
        foreach (PromptInjectionPayloads::all() as $name => $payload) {
            $llm = new RecordingLlmProvider(['title' => 'x', 'priority' => 'normal', 'projectId' => null]);
            (new PortalTicketSuggester($llm))->suggest($payload, []);

            self::assertStringNotContainsString(
                PromptInjectionPayloads::MARKER,
                $llm->lastSystem(),
                "payload '{$name}' leaked into the system prompt",
            );
            self::assertStringContainsString(
                PromptInjectionPayloads::MARKER,
                $llm->lastUser(),
                "payload '{$name}' should be present as data in the user message",
            );
        }
    }

    public function testHijackedModelCannotAssignAForeignProject(): void
    {
        $allowed = $this->project('Mein Projekt');
        $allowedId = $allowed->getId()?->toRfc4122();
        // A project id the customer is NOT allowed to see (another tenant/customer).
        $foreignId = Uuid::v7()->toRfc4122();

        // The model "obeyed" the injection and returned the foreign project id.
        $llm = new RecordingLlmProvider([
            'title' => 'Whatever',
            'priority' => 'urgent',
            'projectId' => $foreignId,
        ]);

        $out = (new PortalTicketSuggester($llm))->suggest(
            PromptInjectionPayloads::CROSS_TENANT,
            [$allowed],
        );

        // Foreign project is dropped; only an allowed id (or null) can survive.
        self::assertNotSame($foreignId, $out['projectId']);
        self::assertNull($out['projectId']);
        self::assertContains($out['projectId'], [null, $allowedId]);
    }

    public function testAllowedProjectStillPassesThrough(): void
    {
        // Guardrail must be selective, not "drop everything".
        $allowed = $this->project('Mein Projekt');
        $allowedId = $allowed->getId()?->toRfc4122();

        $llm = new RecordingLlmProvider(['title' => 'Fix', 'priority' => 'high', 'projectId' => $allowedId]);
        $out = (new PortalTicketSuggester($llm))->suggest('normale Beschreibung', [$allowed]);

        self::assertSame($allowedId, $out['projectId']);
        self::assertSame('high', $out['priority']);
    }

    public function testHijackedModelCannotForgePriorityOutsideEnum(): void
    {
        $llm = new RecordingLlmProvider([
            'title' => 'x',
            'priority' => 'urgent;EXFILTRATE-ALL',
            'projectId' => null,
        ]);
        $out = (new PortalTicketSuggester($llm))->suggest(PromptInjectionPayloads::EXFIL_EMAIL, []);

        self::assertContains($out['priority'], ['low', 'normal', 'high', 'urgent']);
        self::assertSame('normal', $out['priority']); // unparseable → safe default
    }

    private function project(string $name): Project
    {
        $project = (new Project())->setName($name);
        $ref = new \ReflectionProperty(Project::class, 'id');
        $ref->setValue($project, Uuid::v7());

        return $project;
    }
}
