<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add feedback_submissions (admin-only sidecar for the shared feedback board).
 */
final class Version20260716102934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feedback_submissions (admin-only attribution + diagnostics sidecar for feedback tickets).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feedback_submissions (source_app VARCHAR(16) NOT NULL, route VARCHAR(512) DEFAULT NULL, app_version VARCHAR(64) DEFAULT NULL, user_agent LONGTEXT DEFAULT NULL, diagnostics JSON DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, task_id BINARY(16) NOT NULL, submitter_user_id BINARY(16) DEFAULT NULL, submitter_contact_id BINARY(16) DEFAULT NULL, origin_workspace_id BINARY(16) DEFAULT NULL, screenshot_file_id BINARY(16) DEFAULT NULL, UNIQUE INDEX UNIQ_B483DC338DB60186 (task_id), INDEX IDX_B483DC33352F83EF (submitter_user_id), INDEX IDX_B483DC335A7E40C (submitter_contact_id), INDEX IDX_B483DC334CBD3A66 (origin_workspace_id), INDEX IDX_B483DC3375F7E70 (screenshot_file_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE feedback_submissions ADD CONSTRAINT FK_B483DC338DB60186 FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE feedback_submissions ADD CONSTRAINT FK_B483DC33352F83EF FOREIGN KEY (submitter_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE feedback_submissions ADD CONSTRAINT FK_B483DC335A7E40C FOREIGN KEY (submitter_contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE feedback_submissions ADD CONSTRAINT FK_B483DC334CBD3A66 FOREIGN KEY (origin_workspace_id) REFERENCES workspaces (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE feedback_submissions ADD CONSTRAINT FK_B483DC3375F7E70 FOREIGN KEY (screenshot_file_id) REFERENCES files (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback_submissions DROP FOREIGN KEY FK_B483DC338DB60186');
        $this->addSql('ALTER TABLE feedback_submissions DROP FOREIGN KEY FK_B483DC33352F83EF');
        $this->addSql('ALTER TABLE feedback_submissions DROP FOREIGN KEY FK_B483DC335A7E40C');
        $this->addSql('ALTER TABLE feedback_submissions DROP FOREIGN KEY FK_B483DC334CBD3A66');
        $this->addSql('ALTER TABLE feedback_submissions DROP FOREIGN KEY FK_B483DC3375F7E70');
        $this->addSql('DROP TABLE feedback_submissions');
    }
}
