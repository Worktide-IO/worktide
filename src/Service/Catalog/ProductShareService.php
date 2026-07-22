<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Enum\ProductShareStatus;
use App\Entity\Product;
use App\Entity\ProductShare;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Copies a shared product into the target workspace on acceptance.
 * The copy retains the original's name, description, category, status,
 * type and tags. Versions are NOT copied — the receiving workspace
 * manages its own versioning.
 */
final class ProductShareService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function accept(ProductShare $share): Product
    {
        $original = $share->getProduct();
        $target = $share->getTargetWorkspace();

        $copy = new Product();
        $copy->setName($original->getName());
        $copy->setSlug($this->uniqueSlug($original->getSlug(), $target));
        $copy->setType($original->getType());
        $copy->setStatus($original->getStatus());
        $copy->setDescription($original->getDescription());
        $copy->setCategory($original->getCategory());
        $copy->setParent(null);
        $copy->setTranslations($original->getTranslations());
        $copy->setWorkspace($target);
        $copy->setSourceWorkspace($share->getSourceWorkspace());
        $copy->setSourceProduct($original);

        // Copy tags
        foreach ($original->getTags() as $tag) {
            $copy->addTag($tag);
        }

        $this->em->persist($copy);

        $share->setStatus(ProductShareStatus::Accepted);
        $share->setSharedCopy($copy);

        $this->em->flush();

        return $copy;
    }

    private function uniqueSlug(string $slug, mixed $workspace): string
    {
        $existing = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'slug' => $slug]);
        if (!$existing) {
            return $slug;
        }

        $i = 1;
        do {
            $candidate = $slug . '-' . $i++;
        } while ($this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'slug' => $candidate]));

        return $candidate;
    }
}
