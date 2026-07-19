<?php

declare(strict_types=1);

namespace App\Tests\Service\Llm;

use App\Egress\EgressGuard;
use App\Entity\Workspace;
use App\Service\Llm\AiUsageContext;
use App\Service\Llm\AnthropicLlmProvider;
use App\Service\Llm\InfomaniakLlmProvider;
use App\Service\Llm\LlmBudgetGuard;
use App\Service\Llm\LlmException;
use App\Service\Llm\LlmPricing;
use App\Service\Llm\LlmProviderFactory;
use App\Service\Llm\LlmRouter;
use App\Service\Llm\LlmTier;
use App\Service\Llm\LlmUsageRecorder;
use App\Service\Llm\OllamaLlmProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The per-task-type routing policy. Providers are distinguished by their model
 * tag ('local-model' vs. 'cloud-model') so a resolved chain can be asserted
 * without touching the network.
 */
final class LlmRouterTest extends TestCase
{
    public function testDefaultTiersMatchTheRoadmapTable(): void
    {
        $router = $this->makeRouter(localBase: 'http://local/v1');

        // Volume-heavy classification → local.
        self::assertSame(LlmTier::Local, $router->tierFor('triage', null));
        self::assertSame(LlmTier::Local, $router->tierFor('ticket_from_conversation', null));
        self::assertSame(LlmTier::Local, $router->tierFor('tags', null));
        // Cheap suggestions → local with cloud fallback.
        self::assertSame(LlmTier::LocalFallbackCloud, $router->tierFor('estimate', null));
        self::assertSame(LlmTier::LocalFallbackCloud, $router->tierFor('reply', null));
        // Agentic / outbound-drafting + unknown + unattributed → cloud.
        self::assertSame(LlmTier::Cloud, $router->tierFor('schedule', null));
        self::assertSame(LlmTier::Cloud, $router->tierFor('command', null));
        self::assertSame(LlmTier::Cloud, $router->tierFor('made_up_feature', null));
        self::assertSame(LlmTier::Cloud, $router->tierFor(null, null));
    }

    public function testWorkspaceOverrideBeatsTheDefault(): void
    {
        $router = $this->makeRouter(localBase: 'http://local/v1');
        $ws = $this->workspace(['routing' => ['triage' => 'cloud', 'schedule' => 'local']]);

        self::assertSame(LlmTier::Cloud, $router->tierFor('triage', $ws));
        self::assertSame(LlmTier::Local, $router->tierFor('schedule', $ws));
        // An untouched feature still follows the default map.
        self::assertSame(LlmTier::LocalFallbackCloud, $router->tierFor('reply', $ws));
    }

    public function testForceLocalOverridesEveryTaskType(): void
    {
        $router = $this->makeRouter(localBase: 'http://local/v1');
        $ws = $this->workspace(['forceLocal' => true, 'routing' => ['schedule' => 'cloud']]);

        self::assertSame(LlmTier::Local, $router->tierFor('schedule', $ws));
        self::assertSame(LlmTier::Local, $router->tierFor('command', $ws));
    }

    public function testRouteLocalTierWhenConfigured(): void
    {
        [$primary, $fallback] = $this->makeRouter('http://local/v1')->route('triage', null);

        self::assertSame('local-model', $primary->getModel());
        self::assertNull($fallback);
    }

    public function testRouteLocalTierDegradesToCloudWhenLocalUnconfigured(): void
    {
        // Default deployment (no OLLAMA_API_BASE): "local" routes must fall to cloud.
        [$primary, $fallback] = $this->makeRouter(localBase: null)->route('triage', null);

        self::assertSame('cloud-model', $primary->getModel());
        self::assertNull($fallback);
    }

    public function testRouteLocalFallbackCloudChain(): void
    {
        [$primary, $fallback] = $this->makeRouter('http://local/v1')->route('reply', null);

        self::assertSame('local-model', $primary->getModel());
        self::assertNotNull($fallback);
        self::assertSame('cloud-model', $fallback->getModel());
    }

    public function testRouteLocalFallbackCloudWithoutLocalIsCloudOnly(): void
    {
        [$primary, $fallback] = $this->makeRouter(localBase: null)->route('reply', null);

        self::assertSame('cloud-model', $primary->getModel());
        self::assertNull($fallback);
    }

    public function testRouteCloudTier(): void
    {
        [$primary, $fallback] = $this->makeRouter('http://local/v1')->route('schedule', null);

        self::assertSame('cloud-model', $primary->getModel());
        self::assertNull($fallback);
    }

    public function testForceLocalRoutesLocalOnlyWithNoFallback(): void
    {
        $ws = $this->workspace(['forceLocal' => true]);
        [$primary, $fallback] = $this->makeRouter('http://local/v1')->route('reply', $ws);

        self::assertSame('local-model', $primary->getModel());
        self::assertNull($fallback, 'A privacy workspace must never fall back to the cloud.');
    }

    public function testForceLocalFailsClosedWhenLocalUnconfigured(): void
    {
        $ws = $this->workspace(['forceLocal' => true]);

        $this->expectException(LlmException::class);
        $this->makeRouter(localBase: null)->route('triage', $ws);
    }

    public function testIsAnyConfigured(): void
    {
        self::assertTrue($this->makeRouter('http://local/v1')->isAnyConfigured());
        self::assertTrue($this->makeRouter(localBase: null, cloudKey: 'k')->isAnyConfigured());
        self::assertFalse($this->makeRouter(localBase: null, cloudKey: '')->isAnyConfigured());
    }

    /** @param array<string, mixed> $ai */
    private function workspace(array $ai): Workspace
    {
        $ws = new Workspace();
        $ws->setSettings(['ai' => $ai]);

        return $ws;
    }

    private function makeRouter(?string $localBase, string $cloudKey = 'k'): LlmRouter
    {
        $http = $this->createStub(HttpClientInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $ctx = new AiUsageContext();
        $recorder = new LlmUsageRecorder($em, new LlmPricing(), $ctx, $logger);
        $egress = new EgressGuard(null);
        $budget = new LlmBudgetGuard($em);

        $local = new OllamaLlmProvider($http, $recorder, apiBase: $localBase, model: 'local-model');
        $anthropic = new AnthropicLlmProvider($egress, $recorder, $ctx, $budget, apiKey: $cloudKey, model: 'cloud-model');
        $infomaniak = new InfomaniakLlmProvider($http, $egress, $recorder, $ctx, $budget);
        $factory = new LlmProviderFactory($anthropic, $infomaniak, 'anthropic');

        return new LlmRouter($local, $factory);
    }
}
