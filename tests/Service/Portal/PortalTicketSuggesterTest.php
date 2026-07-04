<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\Project;
use App\Service\Llm\LlmProviderInterface;
use App\Service\Portal\PortalTicketSuggester;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PortalTicketSuggesterTest extends TestCase
{
    public function testValidSuggestionIsPassedThrough(): void
    {
        $project = $this->project('Shop-Relaunch');
        $pid = $project->getId()?->toRfc4122();

        $suggester = $this->suggester(['title' => '"Shop lädt langsam"', 'priority' => 'high', 'projectId' => $pid]);
        $out = $suggester->suggest('Der Shop lädt seit heute sehr langsam.', [$project]);

        self::assertSame('Shop lädt langsam', $out['title']); // quotes trimmed
        self::assertSame('high', $out['priority']);
        self::assertSame($pid, $out['projectId']);
    }

    public function testInvalidPriorityFallsBackToNormal(): void
    {
        $suggester = $this->suggester(['title' => 'X', 'priority' => 'kritisch', 'projectId' => null]);
        self::assertSame('normal', $suggester->suggest('irgendwas', [])['priority']);
    }

    public function testUnknownProjectIdIsDropped(): void
    {
        $project = $this->project('Nur dieses');
        $suggester = $this->suggester(['title' => 'X', 'priority' => 'low', 'projectId' => Uuid::v7()->toRfc4122()]);
        self::assertNull($suggester->suggest('text', [$project])['projectId']);
    }

    public function testEmptyTitleFallsBackToFirstLine(): void
    {
        $suggester = $this->suggester(['title' => '', 'priority' => 'normal', 'projectId' => null]);
        $out = $suggester->suggest("Login geht nicht mehr\nseit gestern", []);
        self::assertSame('Login geht nicht mehr', $out['title']);
    }

    public function testAvailabilityReflectsProvider(): void
    {
        self::assertTrue($this->suggester([], true)->isAvailable());
        self::assertFalse($this->suggester([], false)->isAvailable());
    }

    /** @param array<string, mixed> $payload */
    private function suggester(array $payload, bool $configured = true): PortalTicketSuggester
    {
        $llm = new class($payload, $configured) implements LlmProviderInterface {
            /** @param array<string, mixed> $payload */
            public function __construct(private array $payload, private bool $configured) {}

            public function isConfigured(): bool { return $this->configured; }
            public function complete(string $system, string $user, int $maxTokens = 4096): string { return ''; }

            /** @return array<string, mixed> */
            public function completeJson(string $system, string $user, int $maxTokens = 2048): array { return $this->payload; }

            public function getModel(): string { return 'test-model'; }
        };

        return new PortalTicketSuggester($llm);
    }

    private function project(string $name): Project
    {
        $project = (new Project())->setName($name);
        $ref = new \ReflectionProperty(Project::class, 'id');
        $ref->setValue($project, Uuid::v7());

        return $project;
    }
}
