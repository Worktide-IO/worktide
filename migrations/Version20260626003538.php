<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626003538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dashboards: dashboards table (Phase B Schicht 3 — configurable workspace dashboards)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dashboards (name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, icon VARCHAR(40) DEFAULT NULL, color VARCHAR(16) DEFAULT NULL, widgets JSON NOT NULL, position DOUBLE PRECISION NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_A83421DA7D182D95 (created_by_user_id), INDEX IDX_A83421DA2793CC5E (updated_by_user_id), INDEX dashboard_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dashboards ADD CONSTRAINT FK_A83421DA82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dashboards ADD CONSTRAINT FK_A83421DA7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dashboards ADD CONSTRAINT FK_A83421DA2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE dashboards DROP FOREIGN KEY FK_A83421DA82D40A1F');
        $this->addSql('ALTER TABLE dashboards DROP FOREIGN KEY FK_A83421DA7D182D95');
        $this->addSql('ALTER TABLE dashboards DROP FOREIGN KEY FK_A83421DA2793CC5E');
        $this->addSql('DROP TABLE dashboards');
    }
}
