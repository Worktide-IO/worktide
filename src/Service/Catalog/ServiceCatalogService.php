<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\Enum\BillingCycle;
use App\Entity\Service;
use App\Entity\ServiceVersion;
use App\Entity\User;
use App\Repository\ServiceVersionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maintains service-catalogue invariants when a priced version ships — kept in
 * one place so the release endpoint, the data migration and the backfill stay
 * consistent (mirrors {@see ProductCatalogService} for the billing catalogue).
 *
 * A release is the new current version: {@see self::applyCurrent()} flags it
 * `isCurrent`, points {@see Service::$currentVersion} at it, and clears the flag
 * on the previous one. Old versions are kept for the assignments already on them
 * (price history).
 */
final class ServiceCatalogService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceVersionRepository $versions,
    ) {}

    public function releaseVersion(
        Service $service,
        int $netPriceCents,
        string $currency,
        BillingCycle $billingCycle,
        ?string $label = null,
        ?string $changelog = null,
        ?\DateTimeImmutable $effectiveFrom = null,
        ?User $actor = null,
    ): ServiceVersion {
        if ($netPriceCents < 0) {
            throw new \InvalidArgumentException('Net price must not be negative.');
        }

        $version = (new ServiceVersion())
            ->setService($service)
            ->setVersionNo($this->versions->maxVersionNo($service) + 1)
            ->setNetPriceCents($netPriceCents)
            ->setCurrency($currency)
            ->setBillingCycle($billingCycle)
            ->setLabel($label)
            ->setChangelog($changelog)
            ->setEffectiveFrom($effectiveFrom);
        if ($actor !== null) {
            $version->setCreatedByUser($actor);
        }
        $service->addVersion($version);

        $this->applyCurrent($service, $version);

        $this->em->persist($version);
        $this->em->flush();

        return $version;
    }

    /**
     * Make $newest the single current version of $service and clear the flag on
     * the previous one. Idempotent; operates on the in-memory collection.
     */
    public function applyCurrent(Service $service, ServiceVersion $newest): void
    {
        foreach ($service->getVersions() as $v) {
            if ($v !== $newest && $v->isCurrent()) {
                $v->setIsCurrent(false);
            }
        }
        $newest->setIsCurrent(true);
        $service->setCurrentVersion($newest);
    }
}
