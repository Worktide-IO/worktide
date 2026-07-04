<?php

declare(strict_types=1);

namespace App\Tests\Service\ExternalSearch;

use App\Entity\Enum\LeadSource;
use App\Service\ExternalSearch\ExternalSearchQuery;
use App\Service\ExternalSearch\Provider\InternalSearchProvider;
use App\Service\Search\SearchHit;
use App\Service\Search\SearchProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class InternalSearchProviderTest extends TestCase
{
    public function testSkipsForLeadGenerationObjective(): void
    {
        $search = $this->createMock(SearchProviderInterface::class);
        $search->expects(self::never())->method('search');

        $out = (new InternalSearchProvider($search))->search(
            new ExternalSearchQuery('agenturen', 20, ['objective' => 'lead_generation'], Uuid::v7()),
        );
        self::assertSame([], $out);
    }

    public function testReturnsEmptyWithoutWorkspace(): void
    {
        $search = $this->createMock(SearchProviderInterface::class);
        $search->expects(self::never())->method('search');

        $out = (new InternalSearchProvider($search))->search(
            new ExternalSearchQuery('x', 20, ['objective' => 'partner_search']),
        );
        self::assertSame([], $out);
    }

    public function testMapsIndexHitsToReferralResults(): void
    {
        $hit = new SearchHit('customer', 'cust-1', '/v1/customers/cust-1', 'RÖHM GmbH', 'Maschinenbau DACH', null);
        $search = $this->createStub(SearchProviderInterface::class);
        $search->method('search')->willReturn([$hit]);

        $out = (new InternalSearchProvider($search))->search(
            new ExternalSearchQuery('maschinenbau', 20, ['objective' => 'partner_search'], Uuid::v7()),
        );

        self::assertCount(1, $out);
        self::assertSame('RÖHM GmbH', $out[0]->title);
        self::assertSame('internal', $out[0]->provider);
        self::assertSame(LeadSource::Referral, $out[0]->source);
        self::assertSame('internal://customer/cust-1', $out[0]->url);
        self::assertSame('customer', $out[0]->data['internalType']);
    }
}
