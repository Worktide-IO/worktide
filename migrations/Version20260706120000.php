<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add customers.customer_number (human customer number synced from lexoffice).
 */
final class Version20260706120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customers.customer_number (lexoffice customer number)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers ADD customer_number VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers DROP customer_number');
    }
}
