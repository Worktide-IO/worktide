<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625185236 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Status-Updates: project_status_updates table (Phase A Status-Updates)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_status_updates (health VARCHAR(12) DEFAULT \'on_track\' NOT NULL, title VARCHAR(160) DEFAULT NULL, summary LONGTEXT DEFAULT NULL, risks LONGTEXT DEFAULT NULL, next_steps LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_A42F50177D182D95 (created_by_user_id), INDEX IDX_A42F50172793CC5E (updated_by_user_id), INDEX status_update_project_idx (project_id), INDEX status_update_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_status_updates ADD CONSTRAINT FK_A42F5017166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_status_updates ADD CONSTRAINT FK_A42F501782D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_status_updates ADD CONSTRAINT FK_A42F50177D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_status_updates ADD CONSTRAINT FK_A42F50172793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_status_updates DROP FOREIGN KEY FK_A42F5017166D1F9C');
        $this->addSql('ALTER TABLE project_status_updates DROP FOREIGN KEY FK_A42F501782D40A1F');
        $this->addSql('ALTER TABLE project_status_updates DROP FOREIGN KEY FK_A42F50177D182D95');
        $this->addSql('ALTER TABLE project_status_updates DROP FOREIGN KEY FK_A42F50172793CC5E');
        $this->addSql('DROP TABLE project_status_updates');
    }
}
