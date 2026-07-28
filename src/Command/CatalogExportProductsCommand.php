<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\ProductType;
use App\Entity\Product;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:catalog:export-products', description: 'Export product catalogue to JSON for cross-instance transfer')]
final class CatalogExportProductsCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace name', 'WapplerSystems')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Product type (product|service)', 'product')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Output file path', 'var/products-export.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspaceName = $input->getOption('workspace');
        $workspace = $this->em->getRepository(Workspace::class)->findOneBy(['name' => $workspaceName]);
        if (!$workspace) {
            $output->writeln("<error>Workspace {$workspaceName} not found</error>");
            return Command::FAILURE;
        }

        $type = ProductType::from($input->getOption('type'));
        $filePath = $input->getOption('file');

        $products = $this->em->getRepository(Product::class)->findBy([
            'workspace' => $workspace,
            'type' => $type,
        ]);

        $data = [];
        foreach ($products as $product) {
            $data[] = $this->serializeProduct($product);
        }

        $dir = \dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $output->writeln('<info>' . \count($products) . ' products exported to ' . $filePath . '</info>');
        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function serializeProduct(Product $product): array
    {
        $syncedAt = $product->getSyncedAt();

        $versions = [];
        foreach ($product->getVersions() as $version) {
            $rd = $version->getReleaseDate();
            $versions[] = [
                'version' => $version->getVersion(),
                'releaseDate' => $rd?->format('Y-m-d'),
                'releaseNotes' => $version->getReleaseNotes(),
                'status' => $version->getStatus()->value,
                'isLatest' => $version->isLatest(),
            ];
        }

        return [
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'type' => $product->getType()->value,
            'status' => $product->getStatus()->value,
            'description' => $product->getDescription(),
            'category' => $product->getCategory(),
            'translations' => $product->getTranslations(),
            'position' => $product->getPosition(),
            'marketingText' => $product->getMarketingText(),
            'mediaRefs' => $product->getMediaRefs(),
            'sku' => $product->getSku(),
            'priceCents' => $product->getPriceCents(),
            'currency' => $product->getCurrency(),
            'stockQty' => $product->getStockQty(),
            'externalIds' => $product->getExternalIds(),
            'syncedAt' => $syncedAt?->format(\DateTimeInterface::ATOM),
            'attributesText' => $product->getAttributesText(),
            'attributes' => $product->getAttributes(),
            'parentSlug' => $product->getParent()?->getSlug(),
            'versions' => $versions,
        ];
    }
}
