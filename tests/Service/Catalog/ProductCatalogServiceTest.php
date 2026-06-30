<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\Enum\ProductVersionStatus;
use App\Entity\Product;
use App\Entity\ProductVersion;
use App\Service\Catalog\ProductCatalogService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for {@see ProductCatalogService::applyLatest()} — the
 * latest-version bookkeeping — built from in-memory versions (the EM is a stub
 * the method never touches).
 */
final class ProductCatalogServiceTest extends TestCase
{
    private ProductCatalogService $service;

    protected function setUp(): void
    {
        $this->service = new ProductCatalogService($this->createStub(EntityManagerInterface::class));
    }

    private function version(string $v, ProductVersionStatus $status, bool $latest = false): ProductVersion
    {
        return (new ProductVersion())->setVersion($v)->setStatus($status)->setIsLatest($latest);
    }

    public function testFirstReleaseBecomesLatestCurrent(): void
    {
        $product = new Product();
        $v1 = $this->version('1.0.0', ProductVersionStatus::Current);
        $product->getVersions()->add($v1);

        $this->service->applyLatest($product, $v1);

        self::assertTrue($v1->isLatest());
        self::assertSame(ProductVersionStatus::Current, $v1->getStatus());
        self::assertSame($v1, $product->getLatestVersion());
    }

    public function testNewReleaseDemotesPreviousLatest(): void
    {
        $product = new Product();
        $v1 = $this->version('1.0.0', ProductVersionStatus::Current, true);
        $product->getVersions()->add($v1);
        $product->setLatestVersion($v1);

        $v2 = $this->version('2.0.0', ProductVersionStatus::Current);
        $product->getVersions()->add($v2);

        $this->service->applyLatest($product, $v2);

        self::assertTrue($v2->isLatest());
        self::assertSame($v2, $product->getLatestVersion());
        self::assertFalse($v1->isLatest());
        self::assertSame(ProductVersionStatus::Supported, $v1->getStatus(), 'previous current → supported');
    }

    public function testDeprecatedVersionIsNotRePromotedOnDemotion(): void
    {
        $product = new Product();
        $old = $this->version('0.9.0', ProductVersionStatus::Deprecated);
        $current = $this->version('1.0.0', ProductVersionStatus::Current, true);
        $product->getVersions()->add($old);
        $product->getVersions()->add($current);
        $product->setLatestVersion($current);

        $next = $this->version('1.1.0', ProductVersionStatus::Current);
        $product->getVersions()->add($next);

        $this->service->applyLatest($product, $next);

        self::assertSame(ProductVersionStatus::Deprecated, $old->getStatus(), 'deprecated stays deprecated');
        self::assertSame(ProductVersionStatus::Supported, $current->getStatus());
        self::assertTrue($next->isLatest());
    }
}
