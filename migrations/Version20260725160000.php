<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Product.attributes: structured, facetable key→value map (JSON) powering the
 * faceted product display and the ?attr[…] filter.
 */
final class Version20260725160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add products.attributes (JSON) for faceted, arbitrary-property filtering.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD attributes JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP attributes');
    }
}
