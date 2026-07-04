<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704055509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Scoped to #31: customer revenue for the priority score. Unrelated
        // position-column drift from the diff was intentionally dropped.
        $this->addSql('ALTER TABLE customers ADD revenue_cents INT DEFAULT NULL, ADD revenue_synced_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers DROP revenue_cents, DROP revenue_synced_at');
    }
}
