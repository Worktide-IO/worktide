<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613220129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE autopilots (rules JSON NOT NULL, is_enabled TINYINT NOT NULL, last_triggered_at DATETIME DEFAULT NULL, last_evaluated_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_1D5B62EB82D40A1F (workspace_id), INDEX IDX_1D5B62EB7D182D95 (created_by_user_id), INDEX IDX_1D5B62EB2793CC5E (updated_by_user_id), UNIQUE INDEX autopilot_project_unique (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_views (name VARCHAR(120) NOT NULL, color VARCHAR(16) DEFAULT NULL, icon VARCHAR(60) DEFAULT NULL, filter JSON NOT NULL, sort_order JSON NOT NULL, is_shared TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, owner_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_D65487A782D40A1F (workspace_id), INDEX IDX_D65487A77D182D95 (created_by_user_id), INDEX IDX_D65487A72793CC5E (updated_by_user_id), INDEX task_view_owner_idx (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE autopilots ADD CONSTRAINT FK_1D5B62EB166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE autopilots ADD CONSTRAINT FK_1D5B62EB82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE autopilots ADD CONSTRAINT FK_1D5B62EB7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE autopilots ADD CONSTRAINT FK_1D5B62EB2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_views ADD CONSTRAINT FK_D65487A77E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_views ADD CONSTRAINT FK_D65487A782D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_views ADD CONSTRAINT FK_D65487A77D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_views ADD CONSTRAINT FK_D65487A72793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE autopilots DROP FOREIGN KEY FK_1D5B62EB166D1F9C');
        $this->addSql('ALTER TABLE autopilots DROP FOREIGN KEY FK_1D5B62EB82D40A1F');
        $this->addSql('ALTER TABLE autopilots DROP FOREIGN KEY FK_1D5B62EB7D182D95');
        $this->addSql('ALTER TABLE autopilots DROP FOREIGN KEY FK_1D5B62EB2793CC5E');
        $this->addSql('ALTER TABLE task_views DROP FOREIGN KEY FK_D65487A77E3C61F9');
        $this->addSql('ALTER TABLE task_views DROP FOREIGN KEY FK_D65487A782D40A1F');
        $this->addSql('ALTER TABLE task_views DROP FOREIGN KEY FK_D65487A77D182D95');
        $this->addSql('ALTER TABLE task_views DROP FOREIGN KEY FK_D65487A72793CC5E');
        $this->addSql('DROP TABLE autopilots');
        $this->addSql('DROP TABLE task_views');
    }
}
