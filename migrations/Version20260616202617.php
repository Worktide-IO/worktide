<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616202617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_versions (name VARCHAR(80) NOT NULL, description LONGTEXT DEFAULT NULL, effective_date DATE DEFAULT NULL, status VARCHAR(12) DEFAULT \'open\' NOT NULL, sharing VARCHAR(12) DEFAULT \'none\' NOT NULL, wiki_page_title VARCHAR(200) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_2492CB097D182D95 (created_by_user_id), INDEX IDX_2492CB092793CC5E (updated_by_user_id), INDEX project_version_project_idx (project_id), INDEX project_version_workspace_idx (workspace_id), INDEX project_version_effective_idx (effective_date), UNIQUE INDEX project_version_project_name_unique (project_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_versions ADD CONSTRAINT FK_2492CB09166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_versions ADD CONSTRAINT FK_2492CB0982D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_versions ADD CONSTRAINT FK_2492CB097D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_versions ADD CONSTRAINT FK_2492CB092793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tasks ADD fixed_version_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_5058659710E90862 FOREIGN KEY (fixed_version_id) REFERENCES project_versions (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5058659710E90862 ON tasks (fixed_version_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_versions DROP FOREIGN KEY FK_2492CB09166D1F9C');
        $this->addSql('ALTER TABLE project_versions DROP FOREIGN KEY FK_2492CB0982D40A1F');
        $this->addSql('ALTER TABLE project_versions DROP FOREIGN KEY FK_2492CB097D182D95');
        $this->addSql('ALTER TABLE project_versions DROP FOREIGN KEY FK_2492CB092793CC5E');
        $this->addSql('DROP TABLE project_versions');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_5058659710E90862');
        $this->addSql('DROP INDEX IDX_5058659710E90862 ON tasks');
        $this->addSql('ALTER TABLE tasks DROP fixed_version_id');
    }
}
