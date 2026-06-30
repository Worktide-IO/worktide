<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Enum\ProductVersionStatus;
use App\Entity\Product;
use App\Entity\ProductVersion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maintains catalogue invariants when versions ship — kept in one place so the
 * release endpoint and any other caller stay consistent.
 *
 * A release is the new latest version: {@see self::applyLatest()} flags it
 * `isLatest`, points {@see Product::$latestVersion} at it, and demotes the
 * previous latest (Current → Supported). Backporting an older patch isn't
 * modelled in v1 — release always means "newest".
 */
final class ProductCatalogService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function release(
        Product $product,
        string $version,
        ?\DateTimeImmutable $releaseDate = null,
        ?string $releaseNotes = null,
        ?User $actor = null,
    ): ProductVersion {
        if (!$product->isVersioned()) {
            throw new \InvalidArgumentException('Only products (not services) can have versions.');
        }
        $version = trim($version);
        if ($version === '') {
            throw new \InvalidArgumentException('Version must not be empty.');
        }
        foreach ($product->getVersions() as $existing) {
            if ($existing->getVersion() === $version) {
                throw new \InvalidArgumentException(\sprintf('Version "%s" already exists for this product.', $version));
            }
        }

        $pv = (new ProductVersion())
            ->setProduct($product)
            ->setVersion($version)
            ->setReleaseDate($releaseDate)
            ->setReleaseNotes($releaseNotes)
            ->setStatus(ProductVersionStatus::Current);
        if ($actor !== null) {
            $pv->setCreatedByUser($actor);
        }
        $product->addVersion($pv);

        $this->applyLatest($product, $pv);

        $this->em->persist($pv);
        $this->em->flush();

        return $pv;
    }

    /**
     * Make $newest the single latest version of $product and demote the prior
     * current one. Idempotent; operates only on the in-memory collection.
     */
    public function applyLatest(Product $product, ProductVersion $newest): void
    {
        foreach ($product->getVersions() as $v) {
            if ($v === $newest) {
                continue;
            }
            if ($v->isLatest()) {
                $v->setIsLatest(false);
            }
            if ($v->getStatus() === ProductVersionStatus::Current) {
                $v->setStatus(ProductVersionStatus::Supported);
            }
        }
        $newest->setIsLatest(true);
        $newest->setStatus(ProductVersionStatus::Current);
        $product->setLatestVersion($newest);
    }
}
