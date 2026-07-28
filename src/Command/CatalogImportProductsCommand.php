<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\ProductStatus;
use App\Entity\Enum\ProductType;
use App\Entity\Enum\ProductVersionStatus;
use App\Entity\Product;
use App\Entity\ProductVersion;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:catalog:import-products', description: 'Import product catalogue from JSON (cross-instance transfer)')]
final class CatalogImportProductsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Target workspace name', 'WapplerSystems')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Input file path', 'var/products-export.json')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually persist (default is dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspaceName = $input->getOption('workspace');
        $filePath = $input->getOption('file');
        $apply = (bool) $input->getOption('apply');

        $workspace = $this->em->getRepository(Workspace::class)->findOneBy(['name' => $workspaceName]);
        if (!$workspace) {
            $output->writeln("<error>Workspace {$workspaceName} not found</error>");
            return Command::FAILURE;
        }

        if (!file_exists($filePath)) {
            $output->writeln("<error>File not found: {$filePath}</error>");
            return Command::FAILURE;
        }

        $raw = file_get_contents($filePath);
        $records = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($records)) {
            $output->writeln('<error>Invalid JSON: expected array of products</error>');
            return Command::FAILURE;
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $created = $updated = $versionCount = 0;
            $productRepo = $this->em->getRepository(Product::class);

            // --- Pass 1: upsert products by (workspace, slug) ---
            /** @var array<string, Product> $productMap slug => Product */
            $productMap = [];
            /** @var list<array{product: Product, record: array}> $parentQueue */
            $parentQueue = [];

            foreach ($records as $record) {
                $slug = (string) $record['slug'];
                $existing = $productRepo->findOneBy(['workspace' => $workspace, 'slug' => $slug]);
                $isNew = $existing === null;

                $product = $existing ?? new Product();
                if ($isNew) {
                    $product->setWorkspace($workspace);
                    $product->setSlug($slug);
                    ++$created;
                } else {
                    ++$updated;
                }

                $this->hydrateProduct($product, $record);
                $this->em->persist($product);
                $productMap[$slug] = $product;

                $parentQueue[] = ['product' => $product, 'record' => $record];
            }

            $this->em->flush();

            // --- Pass 2: set parents, rebuild versions ---
            foreach ($parentQueue as $entry) {
                $product = $entry['product'];
                $record = $entry['record'];

                $parentSlug = $record['parentSlug'] ?? null;
                if ($parentSlug !== null && isset($productMap[$parentSlug])) {
                    $product->setParent($productMap[$parentSlug]);
                }

                $versions = $record['versions'] ?? [];
                $latestVersionForProduct = null;

                foreach ($versions as $versionData) {
                    $versionString = (string) $versionData['version'];
                    $versionEntity = $this->findOrCreateVersion($product, $workspace, $versionString);
                    $this->hydrateVersion($versionEntity, $versionData, $workspace, $product);

                    if ($versionEntity->isLatest()) {
                        $latestVersionForProduct = $versionEntity;
                    }

                    $this->em->persist($versionEntity);
                    ++$versionCount;
                }

                if ($latestVersionForProduct !== null) {
                    $product->setLatestVersion($latestVersionForProduct);
                }

                $this->em->persist($product);
            }

            $this->em->flush();

            if ($apply) {
                $conn->commit();
                $output->writeln("<info>created={$created} updated={$updated} versions={$versionCount} (applied)</info>");
            } else {
                $conn->rollBack();
                $output->writeln("<info>created={$created} updated={$updated} versions={$versionCount} (dry-run)</info>");
            }
        } catch (\Throwable $e) {
            $conn->rollBack();
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $record */
    private function hydrateProduct(Product $product, array $record): void
    {
        $product->setName((string) $record['name']);
        $product->setType(ProductType::from($record['type']));
        $product->setStatus(ProductStatus::from($record['status']));
        $product->setDescription($record['description'] ?? null);
        $product->setCategory($record['category'] ?? null);
        $product->setTranslations($record['translations'] ?? null);
        $product->setPosition((int) ($record['position'] ?? 0));
        $product->setMarketingText($record['marketingText'] ?? null);
        $product->setMediaRefs($record['mediaRefs'] ?? null);
        $product->setSku($record['sku'] ?? null);
        $product->setPriceCents(isset($record['priceCents']) ? (int) $record['priceCents'] : null);
        $product->setCurrency($record['currency'] ?? null);
        $product->setStockQty(isset($record['stockQty']) ? (float) $record['stockQty'] : null);
        $product->setExternalIds($record['externalIds'] ?? null);

        $syncedAt = $record['syncedAt'] ?? null;
        $product->setSyncedAt($syncedAt !== null ? new \DateTimeImmutable($syncedAt) : null);

        $product->setAttributesText($record['attributesText'] ?? null);
        $product->setAttributes($record['attributes'] ?? null);
    }

    /** @param array<string, mixed> $v */
    private function hydrateVersion(ProductVersion $versionEntity, array $v, Workspace $workspace, Product $product): void
    {
        $versionEntity->setVersion((string) $v['version']);
        $versionEntity->setReleaseDate(
            !empty($v['releaseDate']) ? new \DateTimeImmutable($v['releaseDate']) : null
        );
        $versionEntity->setReleaseNotes($v['releaseNotes'] ?? null);
        $versionEntity->setStatus(ProductVersionStatus::from($v['status']));
        $versionEntity->setIsLatest((bool) ($v['isLatest'] ?? false));
        $versionEntity->setProduct($product);
    }

    private function findOrCreateVersion(Product $product, Workspace $workspace, string $version): ProductVersion
    {
        foreach ($product->getVersions() as $existing) {
            if ($existing->getVersion() === $version) {
                return $existing;
            }
        }

        $new = new ProductVersion();
        $new->setProduct($product);
        $new->setVersion($version);
        return $new;
    }
}
