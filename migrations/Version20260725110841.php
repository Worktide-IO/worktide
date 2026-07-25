<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725110841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product: cross-system reconciliation fields (sku, price, stock, externalIds json, syncedAt, attributesText).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD sku VARCHAR(64) DEFAULT NULL, ADD price_cents INT DEFAULT NULL, ADD currency VARCHAR(3) DEFAULT NULL, ADD stock_qty DOUBLE PRECISION DEFAULT NULL, ADD external_ids JSON DEFAULT NULL, ADD synced_at DATETIME DEFAULT NULL, ADD attributes_text LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX product_sku_idx ON products (sku)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX product_sku_idx ON products');
        $this->addSql('ALTER TABLE products DROP sku, DROP price_cents, DROP currency, DROP stock_qty, DROP external_ids, DROP synced_at, DROP attributes_text');
    }
}
