<?php

declare(strict_types=1);

namespace App\Tests\Service\ExternalSearch;

use App\Egress\EgressGuard;
use App\Entity\Enum\LeadSource;
use App\Service\ExternalSearch\ExternalSearchException;
use App\Service\ExternalSearch\ExternalSearchQuery;
use App\Service\ExternalSearch\Provider\SurfeSearchProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit coverage for the Surfe people-search adapter over a mocked HTTP client:
 * inert without a key, egress-gated, no call without a targeting signal, and
 * defensive mapping of a sample response to LinkedIn-sourced results.
 */
final class SurfeSearchProviderTest extends TestCase
{
    public function testIsInertWithoutKey(): void
    {
        $provider = new SurfeSearchProvider(new EgressGuard('external_search'), new MockHttpClient(), null);

        self::assertFalse($provider->isConfigured());
        self::assertSame('surfe', $provider->getName());
    }

    public function testReturnsEmptyWithoutTargetingSignal(): void
    {
        // No industry/seniority/department/jobTitle/… and no free-text query → no call.
        $http = new MockHttpClient(function (): never {
            self::fail('Surfe must not be called without a targeting signal.');
        });
        $provider = new SurfeSearchProvider(new EgressGuard('external_search'), $http, 'key');

        self::assertSame([], $provider->search(new ExternalSearchQuery('', 20)));
    }

    public function testThrowsWhenEgressDenied(): void
    {
        $provider = new SurfeSearchProvider(new EgressGuard(''), new MockHttpClient(), 'key');

        $this->expectException(ExternalSearchException::class);
        $provider->search(new ExternalSearchQuery('CTO', 20, ['industries' => 'Software']));
    }

    public function testMapsPeopleToLinkedInResults(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode($options['body'] ?? '{}', true);

            return new MockResponse(json_encode(['people' => [[
                'firstName' => 'Anna',
                'lastName' => 'Muster',
                'companyName' => 'RÖHM GmbH',
                'jobTitle' => 'Head of Engineering',
                'linkedInUrl' => 'https://www.linkedin.com/in/anna-muster',
                'emails' => [['email' => 'anna@roehm.de']],
            ]]]) ?: '', ['http_code' => 200]);
        });
        $provider = new SurfeSearchProvider(new EgressGuard('external_search'), $http, 'key');

        $out = $provider->search(new ExternalSearchQuery('CTO', 20, [
            'industries' => 'Software',
            'seniorities' => 'Head, __bogus__',
            'departments' => 'Engineering',
        ]));

        self::assertCount(1, $out);
        self::assertSame('Anna Muster', $out[0]->title);
        self::assertSame('surfe', $out[0]->provider);
        self::assertSame(LeadSource::LinkedIn, $out[0]->source);
        self::assertSame('https://www.linkedin.com/in/anna-muster', $out[0]->url);
        self::assertSame('Head of Engineering @ RÖHM GmbH', $out[0]->snippet);
        self::assertSame('anna@roehm.de', $out[0]->data['emails'][0]['email']);

        // DACH default applied, unknown seniority dropped, query used as job-title keyword.
        self::assertSame(['DE', 'AT', 'CH'], $captured['companies']['countries']);
        self::assertSame(['Software'], $captured['companies']['industries']);
        self::assertSame(['Head'], $captured['people']['seniorities']);
        self::assertSame(['Engineering'], $captured['people']['departments']);
        self::assertSame(['CTO'], $captured['people']['jobTitles']);
    }
}
