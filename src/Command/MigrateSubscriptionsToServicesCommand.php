<?php

declare(strict_types=1);

namespace App\Command;

use App\Channels\SyncReentryGuard;
use App\Entity\Service;
use App\Entity\ServiceAssignment;
use App\Entity\ServiceSubscription;
use App\Entity\ServiceVersion;
use App\Repository\ServiceRepository;
use App\Service\Catalog\ServiceCatalogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ONE-OFF data migration — moves every legacy {@see ServiceSubscription} row onto
 * the new service catalogue (Service → ServiceVersion → ServiceAssignment). Tracked
 * (not private) so it deploys and can be run once per environment after the schema
 * migration that creates the new tables, before the migration that drops
 * service_subscriptions.
 *
 *   bin/console app:migrate:subscriptions-to-services [--apply]
 *
 * Grouping: (workspace, name) → Service; (service, priceCents, currency, cycle) →
 * ServiceVersion (distinct prices become distinct versions); each row → one
 * ServiceAssignment carrying its dates/status/notes/externalRef. Because a version
 * is created FROM the row's own price, no per-assignment price override is needed.
 *
 * Idempotent: services matched by name, versions by (price, currency, cycle),
 * assignments by external ref (or customer+version+startedOn when unref'd). Dry-run
 * by default; --apply writes inside SyncReentryGuard.
 */
#[AsCommand(
    name: 'app:migrate:subscriptions-to-services',
    description: 'One-off: migrate legacy ServiceSubscription rows to the Service catalogue.',
)]
final class MigrateSubscriptionsToServicesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceRepository $services,
        private readonly ServiceCatalogService $catalog,
        private readonly SyncReentryGuard $guard,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Write (default: dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        /** @var list<ServiceSubscription> $subs */
        $subs = $this->em->getRepository(ServiceSubscription::class)->findBy(['deletedAt' => null]);
        $io->writeln(sprintf('<info>%d legacy subscriptions found.</info>', \count($subs)));

        $servicesCreated = 0;
        $versionsCreated = 0;
        $assignmentsCreated = 0;
        $assignmentsUpdated = 0;

        // In-run caches so grouping works before the flush makes rows queryable.
        /** @var array<string, Service> $serviceByKey */
        $serviceByKey = [];

        $run = function () use ($subs, $apply, &$serviceByKey, &$servicesCreated, &$versionsCreated, &$assignmentsCreated, &$assignmentsUpdated): void {
            foreach ($subs as $sub) {
                $workspace = $sub->getWorkspace();
                $name = $sub->getName();
                $svcKey = $workspace->getId()?->toRfc4122() . '|' . $name;

                // Service (by workspace + name).
                $service = $serviceByKey[$svcKey] ?? $this->services->findOneByName($workspace, $name);
                if ($service === null) {
                    $service = (new Service())->setName($name)->setDescription($sub->getDescription());
                    $service->setWorkspace($workspace);
                    if ($apply) {
                        $this->em->persist($service);
                    }
                    ++$servicesCreated;
                }
                $serviceByKey[$svcKey] = $service;

                // Version (by price + currency + cycle within the service).
                $version = $this->findVersion($service, $sub->getPriceCents(), $sub->getCurrency(), $sub->getBillingCycle()->value);
                if ($version === null) {
                    if ($apply) {
                        $version = $this->catalog->releaseVersion(
                            $service,
                            $sub->getPriceCents(),
                            $sub->getCurrency(),
                            $sub->getBillingCycle(),
                            'Migriert aus ServiceSubscription',
                        );
                    } else {
                        // Dry-run: fabricate a detached stand-in so grouping counts are right.
                        $version = (new ServiceVersion())
                            ->setService($service)
                            ->setNetPriceCents($sub->getPriceCents())
                            ->setCurrency($sub->getCurrency())
                            ->setBillingCycle($sub->getBillingCycle());
                        $service->addVersion($version);
                    }
                    ++$versionsCreated;
                }

                // Assignment.
                $assignment = $this->findAssignment($sub);
                if ($assignment === null) {
                    $assignment = new ServiceAssignment();
                    ++$assignmentsCreated;
                } else {
                    ++$assignmentsUpdated;
                }
                $assignment
                    ->setCustomer($sub->getCustomer())
                    ->setSystem($sub->getSystem())
                    ->setServiceVersion($version)
                    ->setStartedOn($sub->getStartedOn())
                    ->setEndedOn($sub->getEndedOn())
                    ->setNotes($sub->getNotes())
                    ->setAutoRenew($sub->isAutoRenew())
                    ->setStatus($sub->getStatus())
                    ->setExternalSource($sub->getExternalSource())
                    ->setExternalId($sub->getExternalId());

                if ($apply) {
                    $this->em->persist($assignment);
                }
            }
            if ($apply) {
                $this->em->flush();
            }
        };

        $apply ? $this->guard->run($run) : $run();

        $io->section($apply ? 'Migriert' : 'Dry-Run');
        $io->listing([
            sprintf('Services neu: %d', $servicesCreated),
            sprintf('Versionen neu: %d', $versionsCreated),
            sprintf('Assignments neu: %d', $assignmentsCreated),
            sprintf('Assignments aktualisiert: %d', $assignmentsUpdated),
        ]);
        if (!$apply) {
            $io->note('Dry-Run — nichts geschrieben. Mit --apply ausführen.');
        }

        return Command::SUCCESS;
    }

    private function findVersion(Service $service, int $priceCents, string $currency, string $cycle): ?ServiceVersion
    {
        foreach ($service->getVersions() as $v) {
            if (
                $v->getNetPriceCents() === $priceCents
                && $v->getCurrency() === strtolower(trim($currency))
                && $v->getBillingCycle()->value === $cycle
            ) {
                return $v;
            }
        }

        return null;
    }

    private function findAssignment(ServiceSubscription $sub): ?ServiceAssignment
    {
        $repo = $this->em->getRepository(ServiceAssignment::class);

        if ($sub->getExternalSource() !== null && $sub->getExternalId() !== null) {
            return $repo->findOneBy([
                'workspace' => $sub->getWorkspace(),
                'externalSource' => $sub->getExternalSource(),
                'externalId' => $sub->getExternalId(),
            ]);
        }

        return $repo->findOneBy([
            'customer' => $sub->getCustomer(),
            'startedOn' => $sub->getStartedOn(),
            'deletedAt' => null,
        ]);
    }
}
