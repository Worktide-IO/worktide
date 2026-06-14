<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614100140 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sweep — ActiveTimer + Task/TimeEntry.project becomes nullable (private tasks)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE active_timers (started_at DATETIME NOT NULL, description LONGTEXT DEFAULT NULL, is_billable TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, task_id BINARY(16) DEFAULT NULL, project_id BINARY(16) DEFAULT NULL, type_of_work_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_793F3FB88DB60186 (task_id), INDEX IDX_793F3FB8166D1F9C (project_id), INDEX IDX_793F3FB85B42744F (type_of_work_id), INDEX active_timer_workspace_idx (workspace_id), UNIQUE INDEX active_timer_user_unique (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE active_timers ADD CONSTRAINT FK_793F3FB8A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE active_timers ADD CONSTRAINT FK_793F3FB88DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE active_timers ADD CONSTRAINT FK_793F3FB8166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE active_timers ADD CONSTRAINT FK_793F3FB85B42744F FOREIGN KEY (type_of_work_id) REFERENCES types_of_work (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE active_timers ADD CONSTRAINT FK_793F3FB882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tasks CHANGE project_id project_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY `FK_797F12A3166D1F9C`');
        $this->addSql('ALTER TABLE time_entries CHANGE project_id project_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT FK_797F12A3166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE active_timers DROP FOREIGN KEY FK_793F3FB8A76ED395');
        $this->addSql('ALTER TABLE active_timers DROP FOREIGN KEY FK_793F3FB88DB60186');
        $this->addSql('ALTER TABLE active_timers DROP FOREIGN KEY FK_793F3FB8166D1F9C');
        $this->addSql('ALTER TABLE active_timers DROP FOREIGN KEY FK_793F3FB85B42744F');
        $this->addSql('ALTER TABLE active_timers DROP FOREIGN KEY FK_793F3FB882D40A1F');
        $this->addSql('DROP TABLE active_timers');
        $this->addSql('ALTER TABLE tasks CHANGE project_id project_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE time_entries DROP FOREIGN KEY FK_797F12A3166D1F9C');
        $this->addSql('ALTER TABLE time_entries CHANGE project_id project_id BINARY(16) NOT NULL');
        $this->addSql('ALTER TABLE time_entries ADD CONSTRAINT `FK_797F12A3166D1F9C` FOREIGN KEY (project_id) REFERENCES projects (id) ON UPDATE NO ACTION ON DELETE CASCADE');
    }
}
