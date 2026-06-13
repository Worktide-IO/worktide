<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613210943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE automation_actions (type VARCHAR(32) NOT NULL, config JSON NOT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, automation_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_A681CE4C7D182D95 (created_by_user_id), INDEX IDX_A681CE4C2793CC5E (updated_by_user_id), INDEX automation_action_automation_idx (automation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE automations (name VARCHAR(120) NOT NULL, trigger_type VARCHAR(32) NOT NULL, trigger_config JSON NOT NULL, is_enabled TINYINT NOT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, workflow_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_5C7573E82C7C2CBA (workflow_id), INDEX IDX_5C7573E882D40A1F (workspace_id), INDEX IDX_5C7573E87D182D95 (created_by_user_id), INDEX IDX_5C7573E82793CC5E (updated_by_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_schedules (name VARCHAR(120) NOT NULL, cron_expression VARCHAR(100) NOT NULL, timezone VARCHAR(64) NOT NULL, next_run_at DATETIME DEFAULT NULL, last_run_at DATETIME DEFAULT NULL, is_enabled TINYINT NOT NULL, task_title VARCHAR(200) NOT NULL, task_description LONGTEXT DEFAULT NULL, task_priority VARCHAR(12) NOT NULL, task_estimated_minutes INT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, task_assignee_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_4E1111DF166D1F9C (project_id), INDEX IDX_4E1111DF1F99D052 (task_assignee_id), INDEX IDX_4E1111DF82D40A1F (workspace_id), INDEX IDX_4E1111DF7D182D95 (created_by_user_id), INDEX IDX_4E1111DF2793CC5E (updated_by_user_id), INDEX task_schedule_next_run_idx (next_run_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workflows (name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, color VARCHAR(16) NOT NULL, is_enabled TINYINT NOT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_EFBFBFC282D40A1F (workspace_id), INDEX IDX_EFBFBFC27D182D95 (created_by_user_id), INDEX IDX_EFBFBFC22793CC5E (updated_by_user_id), UNIQUE INDEX workflow_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE automation_actions ADD CONSTRAINT FK_A681CE4CD1C5DDC3 FOREIGN KEY (automation_id) REFERENCES automations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE automation_actions ADD CONSTRAINT FK_A681CE4C7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE automation_actions ADD CONSTRAINT FK_A681CE4C2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE automations ADD CONSTRAINT FK_5C7573E82C7C2CBA FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE automations ADD CONSTRAINT FK_5C7573E882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE automations ADD CONSTRAINT FK_5C7573E87D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE automations ADD CONSTRAINT FK_5C7573E82793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_schedules ADD CONSTRAINT FK_4E1111DF166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_schedules ADD CONSTRAINT FK_4E1111DF1F99D052 FOREIGN KEY (task_assignee_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_schedules ADD CONSTRAINT FK_4E1111DF82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_schedules ADD CONSTRAINT FK_4E1111DF7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_schedules ADD CONSTRAINT FK_4E1111DF2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workflows ADD CONSTRAINT FK_EFBFBFC282D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflows ADD CONSTRAINT FK_EFBFBFC27D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE workflows ADD CONSTRAINT FK_EFBFBFC22793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE projects ADD workflow_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE projects ADD CONSTRAINT FK_5C93B3A42C7C2CBA FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5C93B3A42C7C2CBA ON projects (workflow_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE automation_actions DROP FOREIGN KEY FK_A681CE4CD1C5DDC3');
        $this->addSql('ALTER TABLE automation_actions DROP FOREIGN KEY FK_A681CE4C7D182D95');
        $this->addSql('ALTER TABLE automation_actions DROP FOREIGN KEY FK_A681CE4C2793CC5E');
        $this->addSql('ALTER TABLE automations DROP FOREIGN KEY FK_5C7573E82C7C2CBA');
        $this->addSql('ALTER TABLE automations DROP FOREIGN KEY FK_5C7573E882D40A1F');
        $this->addSql('ALTER TABLE automations DROP FOREIGN KEY FK_5C7573E87D182D95');
        $this->addSql('ALTER TABLE automations DROP FOREIGN KEY FK_5C7573E82793CC5E');
        $this->addSql('ALTER TABLE task_schedules DROP FOREIGN KEY FK_4E1111DF166D1F9C');
        $this->addSql('ALTER TABLE task_schedules DROP FOREIGN KEY FK_4E1111DF1F99D052');
        $this->addSql('ALTER TABLE task_schedules DROP FOREIGN KEY FK_4E1111DF82D40A1F');
        $this->addSql('ALTER TABLE task_schedules DROP FOREIGN KEY FK_4E1111DF7D182D95');
        $this->addSql('ALTER TABLE task_schedules DROP FOREIGN KEY FK_4E1111DF2793CC5E');
        $this->addSql('ALTER TABLE workflows DROP FOREIGN KEY FK_EFBFBFC282D40A1F');
        $this->addSql('ALTER TABLE workflows DROP FOREIGN KEY FK_EFBFBFC27D182D95');
        $this->addSql('ALTER TABLE workflows DROP FOREIGN KEY FK_EFBFBFC22793CC5E');
        $this->addSql('DROP TABLE automation_actions');
        $this->addSql('DROP TABLE automations');
        $this->addSql('DROP TABLE task_schedules');
        $this->addSql('DROP TABLE workflows');
        $this->addSql('ALTER TABLE projects DROP FOREIGN KEY FK_5C93B3A42C7C2CBA');
        $this->addSql('DROP INDEX IDX_5C93B3A42C7C2CBA ON projects');
        $this->addSql('ALTER TABLE projects DROP workflow_id');
    }
}
