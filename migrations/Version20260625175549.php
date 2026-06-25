<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625175549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Public Forms: public_forms + public_form_submissions tables (Phase B.4 Public Forms)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE public_form_submissions (payload JSON NOT NULL, remote_ip VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, form_id BINARY(16) NOT NULL, created_task_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_BF2B70BBCBDC13C0 (created_task_id), INDEX public_form_submission_form_idx (form_id), INDEX public_form_submission_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE public_forms (slug VARCHAR(60) NOT NULL, title VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, success_message LONGTEXT DEFAULT NULL, is_enabled TINYINT DEFAULT 1 NOT NULL, default_priority VARCHAR(8) DEFAULT NULL, fields JSON NOT NULL, submission_limit INT DEFAULT NULL, submission_count INT DEFAULT 0 NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, default_status_id BINARY(16) DEFAULT NULL, default_tracker_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_C90A4F7FA95281A (default_status_id), INDEX IDX_C90A4F747967768 (default_tracker_id), INDEX IDX_C90A4F77D182D95 (created_by_user_id), INDEX IDX_C90A4F72793CC5E (updated_by_user_id), INDEX public_form_workspace_idx (workspace_id), INDEX public_form_project_idx (project_id), UNIQUE INDEX public_form_slug_unique (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE public_form_submissions ADD CONSTRAINT FK_BF2B70BB5FF69B7D FOREIGN KEY (form_id) REFERENCES public_forms (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE public_form_submissions ADD CONSTRAINT FK_BF2B70BBCBDC13C0 FOREIGN KEY (created_task_id) REFERENCES tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE public_form_submissions ADD CONSTRAINT FK_BF2B70BB82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE public_forms ADD CONSTRAINT FK_C90A4F7166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE public_forms ADD CONSTRAINT FK_C90A4F7FA95281A FOREIGN KEY (default_status_id) REFERENCES task_statuses (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE public_forms ADD CONSTRAINT FK_C90A4F747967768 FOREIGN KEY (default_tracker_id) REFERENCES trackers (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE public_forms ADD CONSTRAINT FK_C90A4F782D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE public_forms ADD CONSTRAINT FK_C90A4F77D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE public_forms ADD CONSTRAINT FK_C90A4F72793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE public_form_submissions DROP FOREIGN KEY FK_BF2B70BB5FF69B7D');
        $this->addSql('ALTER TABLE public_form_submissions DROP FOREIGN KEY FK_BF2B70BBCBDC13C0');
        $this->addSql('ALTER TABLE public_form_submissions DROP FOREIGN KEY FK_BF2B70BB82D40A1F');
        $this->addSql('ALTER TABLE public_forms DROP FOREIGN KEY FK_C90A4F7166D1F9C');
        $this->addSql('ALTER TABLE public_forms DROP FOREIGN KEY FK_C90A4F7FA95281A');
        $this->addSql('ALTER TABLE public_forms DROP FOREIGN KEY FK_C90A4F747967768');
        $this->addSql('ALTER TABLE public_forms DROP FOREIGN KEY FK_C90A4F782D40A1F');
        $this->addSql('ALTER TABLE public_forms DROP FOREIGN KEY FK_C90A4F77D182D95');
        $this->addSql('ALTER TABLE public_forms DROP FOREIGN KEY FK_C90A4F72793CC5E');
        $this->addSql('DROP TABLE public_form_submissions');
        $this->addSql('DROP TABLE public_forms');
    }
}
