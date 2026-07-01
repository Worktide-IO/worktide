<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701002737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE industries (name VARCHAR(120) NOT NULL, position DOUBLE PRECISION DEFAULT 0 NOT NULL, is_archived TINYINT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_667A528B7D182D95 (created_by_user_id), INDEX IDX_667A528B2793CC5E (updated_by_user_id), INDEX industry_workspace_idx (workspace_id), UNIQUE INDEX industry_ws_name_uniq (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE industries ADD CONSTRAINT FK_667A528B82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE industries ADD CONSTRAINT FK_667A528B7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE industries ADD CONSTRAINT FK_667A528B2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE customers ADD industry_id BINARY(16) DEFAULT NULL, DROP industry');
        $this->addSql('ALTER TABLE customers ADD CONSTRAINT FK_62534E212B19A734 FOREIGN KEY (industry_id) REFERENCES industries (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_62534E212B19A734 ON customers (industry_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE industries DROP FOREIGN KEY FK_667A528B82D40A1F');
        $this->addSql('ALTER TABLE industries DROP FOREIGN KEY FK_667A528B7D182D95');
        $this->addSql('ALTER TABLE industries DROP FOREIGN KEY FK_667A528B2793CC5E');
        $this->addSql('ALTER TABLE customers DROP FOREIGN KEY FK_62534E212B19A734');
        $this->addSql('DROP INDEX IDX_62534E212B19A734 ON customers');
        $this->addSql('ALTER TABLE customers ADD industry VARCHAR(120) DEFAULT NULL, DROP industry_id');
        $this->addSql('DROP TABLE industries');
    }
}
