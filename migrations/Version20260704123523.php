<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704123523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portal Ideen-Pitch: project_proposals table.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_proposals (title VARCHAR(200) NOT NULL, rationale LONGTEXT DEFAULT NULL, expected_benefit LONGTEXT DEFAULT NULL, effort_hours INT DEFAULT NULL, cost_cents INT DEFAULT NULL, currency VARCHAR(3) NOT NULL, timeframe_text VARCHAR(80) DEFAULT NULL, status VARCHAR(16) DEFAULT \'new\' NOT NULL, origin VARCHAR(16) DEFAULT \'agency\' NOT NULL, variants JSON NOT NULL, customer_feedback LONGTEXT DEFAULT NULL, position INT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, project_id BINARY(16) NOT NULL, converted_task_id BINARY(16) DEFAULT NULL, converted_agreement_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_B4867F58DC10C5B6 (converted_task_id), INDEX IDX_B4867F582D2873B (converted_agreement_id), INDEX IDX_B4867F587D182D95 (created_by_user_id), INDEX IDX_B4867F582793CC5E (updated_by_user_id), INDEX project_proposal_project_idx (project_id), INDEX project_proposal_workspace_idx (workspace_id), INDEX project_proposal_status_idx (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_proposals ADD CONSTRAINT FK_B4867F58166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_proposals ADD CONSTRAINT FK_B4867F58DC10C5B6 FOREIGN KEY (converted_task_id) REFERENCES tasks (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_proposals ADD CONSTRAINT FK_B4867F582D2873B FOREIGN KEY (converted_agreement_id) REFERENCES customer_agreements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_proposals ADD CONSTRAINT FK_B4867F5882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_proposals ADD CONSTRAINT FK_B4867F587D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_proposals ADD CONSTRAINT FK_B4867F582793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_proposals DROP FOREIGN KEY FK_B4867F58166D1F9C');
        $this->addSql('ALTER TABLE project_proposals DROP FOREIGN KEY FK_B4867F58DC10C5B6');
        $this->addSql('ALTER TABLE project_proposals DROP FOREIGN KEY FK_B4867F582D2873B');
        $this->addSql('ALTER TABLE project_proposals DROP FOREIGN KEY FK_B4867F5882D40A1F');
        $this->addSql('ALTER TABLE project_proposals DROP FOREIGN KEY FK_B4867F587D182D95');
        $this->addSql('ALTER TABLE project_proposals DROP FOREIGN KEY FK_B4867F582793CC5E');
        $this->addSql('DROP TABLE project_proposals');
    }
}
