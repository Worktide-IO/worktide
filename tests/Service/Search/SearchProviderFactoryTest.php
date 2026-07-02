<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\MeilisearchClientFactory;
use App\Service\Search\MeilisearchProvider;
use App\Service\Search\MysqlSearchProvider;
use App\Service\Search\SearchDocumentFactory;
use App\Service\Search\SearchProviderFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SearchProviderFactoryTest extends TestCase
{
    public function testSelectsMeilisearchWhenConfigured(): void
    {
        [$mysql, $meili] = $this->providers();
        self::assertSame($meili, (new SearchProviderFactory('meilisearch', $mysql, $meili))->create());
    }

    public function testDefaultsToMysql(): void
    {
        [$mysql, $meili] = $this->providers();
        self::assertSame($mysql, (new SearchProviderFactory('mysql', $mysql, $meili))->create());
        self::assertSame($mysql, (new SearchProviderFactory('', $mysql, $meili))->create());
        self::assertSame($mysql, (new SearchProviderFactory('  MYSQL  ', $mysql, $meili))->create());
    }

    /**
     * @return array{MysqlSearchProvider, MeilisearchProvider}
     */
    private function providers(): array
    {
        $mysql = new MysqlSearchProvider($this->createStub(EntityManagerInterface::class), new SearchDocumentFactory());
        $meili = new MeilisearchProvider(new MeilisearchClientFactory('', ''), 'worktide');

        return [$mysql, $meili];
    }
}
