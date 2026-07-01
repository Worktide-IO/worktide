<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * customers.is_customer / is_vendor — business-relationship type mirrored from
 * external systems (lexoffice roles). Added with a temporary DEFAULT to backfill
 * existing rows (all treated as customers), then the default is dropped so the
 * schema matches the driftless Doctrine mapping.
 */
final class Version20260701050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'customers: add is_customer / is_vendor relationship-type flags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers ADD is_customer TINYINT(1) NOT NULL DEFAULT 1, ADD is_vendor TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE customers ALTER is_customer DROP DEFAULT');
        $this->addSql('ALTER TABLE customers ALTER is_vendor DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers DROP is_customer, DROP is_vendor');
    }
}
