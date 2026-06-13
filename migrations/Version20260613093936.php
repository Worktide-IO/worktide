<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613093936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_milestones (name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, color VARCHAR(16) NOT NULL, due_on DATETIME DEFAULT NULL, position INT NOT NULL, is_reached TINYINT NOT NULL, reached_on DATETIME DEFAULT NULL, is_archived TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, reached_by_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_51FAB65FF3EA70 (reached_by_id), INDEX IDX_51FAB682D40A1F (workspace_id), INDEX IDX_51FAB67D182D95 (created_by_user_id), INDEX IDX_51FAB62793CC5E (updated_by_user_id), INDEX milestone_project_idx (project_id), INDEX milestone_due_idx (due_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_milestone_tasks (project_milestone_id BINARY(16) NOT NULL, task_id BINARY(16) NOT NULL, INDEX IDX_3B14EF2A6199CAB9 (project_milestone_id), INDEX IDX_3B14EF2A8DB60186 (task_id), PRIMARY KEY (project_milestone_id, task_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_dependencies (type VARCHAR(24) NOT NULL, lag_minutes INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, predecessor_id BINARY(16) NOT NULL, successor_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_229E54A082D40A1F (workspace_id), INDEX IDX_229E54A07D182D95 (created_by_user_id), INDEX IDX_229E54A02793CC5E (updated_by_user_id), INDEX task_dependency_predecessor_idx (predecessor_id), INDEX task_dependency_successor_idx (successor_id), UNIQUE INDEX task_dependency_unique (predecessor_id, successor_id, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_milestones ADD CONSTRAINT FK_51FAB6166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_milestones ADD CONSTRAINT FK_51FAB65FF3EA70 FOREIGN KEY (reached_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_milestones ADD CONSTRAINT FK_51FAB682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_milestones ADD CONSTRAINT FK_51FAB67D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_milestones ADD CONSTRAINT FK_51FAB62793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_milestone_tasks ADD CONSTRAINT FK_3B14EF2A6199CAB9 FOREIGN KEY (project_milestone_id) REFERENCES project_milestones (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_milestone_tasks ADD CONSTRAINT FK_3B14EF2A8DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_dependencies ADD CONSTRAINT FK_229E54A068C90015 FOREIGN KEY (predecessor_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_dependencies ADD CONSTRAINT FK_229E54A07323E667 FOREIGN KEY (successor_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_dependencies ADD CONSTRAINT FK_229E54A082D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_dependencies ADD CONSTRAINT FK_229E54A07D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_dependencies ADD CONSTRAINT FK_229E54A02793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_milestones DROP FOREIGN KEY FK_51FAB6166D1F9C');
        $this->addSql('ALTER TABLE project_milestones DROP FOREIGN KEY FK_51FAB65FF3EA70');
        $this->addSql('ALTER TABLE project_milestones DROP FOREIGN KEY FK_51FAB682D40A1F');
        $this->addSql('ALTER TABLE project_milestones DROP FOREIGN KEY FK_51FAB67D182D95');
        $this->addSql('ALTER TABLE project_milestones DROP FOREIGN KEY FK_51FAB62793CC5E');
        $this->addSql('ALTER TABLE project_milestone_tasks DROP FOREIGN KEY FK_3B14EF2A6199CAB9');
        $this->addSql('ALTER TABLE project_milestone_tasks DROP FOREIGN KEY FK_3B14EF2A8DB60186');
        $this->addSql('ALTER TABLE task_dependencies DROP FOREIGN KEY FK_229E54A068C90015');
        $this->addSql('ALTER TABLE task_dependencies DROP FOREIGN KEY FK_229E54A07323E667');
        $this->addSql('ALTER TABLE task_dependencies DROP FOREIGN KEY FK_229E54A082D40A1F');
        $this->addSql('ALTER TABLE task_dependencies DROP FOREIGN KEY FK_229E54A07D182D95');
        $this->addSql('ALTER TABLE task_dependencies DROP FOREIGN KEY FK_229E54A02793CC5E');
        $this->addSql('DROP TABLE project_milestones');
        $this->addSql('DROP TABLE project_milestone_tasks');
        $this->addSql('DROP TABLE task_dependencies');
    }
}
