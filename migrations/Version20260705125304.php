<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705125304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE invoices (lexoffice_id VARCHAR(64) NOT NULL, number VARCHAR(64) NOT NULL, issued_on DATE NOT NULL, due_on DATE DEFAULT NULL, total_cents INT NOT NULL, open_cents INT DEFAULT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(12) DEFAULT \'open\' NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, customer_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_6A2F2F9582D40A1F (workspace_id), INDEX invoice_customer_idx (customer_id), UNIQUE INDEX invoice_lexoffice_uniq (workspace_id, lexoffice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F959395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F9582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F959395C3F3');
        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F9582D40A1F');
        $this->addSql('DROP TABLE invoices');
    }
}
