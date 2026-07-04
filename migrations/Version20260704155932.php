<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704155932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE brainstorm_notes (body LONGTEXT NOT NULL, origin VARCHAR(16) DEFAULT \'customer\' NOT NULL, author_name VARCHAR(120) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, customer_id BINARY(16) NOT NULL, author_contact_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_801A866DA5A859F1 (author_contact_id), INDEX IDX_801A866D82D40A1F (workspace_id), INDEX brainstorm_customer_idx (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE brainstorm_notes ADD CONSTRAINT FK_801A866D9395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE brainstorm_notes ADD CONSTRAINT FK_801A866DA5A859F1 FOREIGN KEY (author_contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE brainstorm_notes ADD CONSTRAINT FK_801A866D82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE brainstorm_notes DROP FOREIGN KEY FK_801A866D9395C3F3');
        $this->addSql('ALTER TABLE brainstorm_notes DROP FOREIGN KEY FK_801A866DA5A859F1');
        $this->addSql('ALTER TABLE brainstorm_notes DROP FOREIGN KEY FK_801A866D82D40A1F');
        $this->addSql('DROP TABLE brainstorm_notes');
    }
}
