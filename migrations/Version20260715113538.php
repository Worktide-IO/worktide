<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715113538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add llm_usage_logs (per-call LLM token + cost accounting).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE llm_usage_logs (feature VARCHAR(40) DEFAULT NULL, provider VARCHAR(20) NOT NULL, model VARCHAR(80) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, cost_micros BIGINT NOT NULL, ok TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workspace_id BINARY(16) DEFAULT NULL, INDEX IDX_6CEFC5382D40A1F (workspace_id), INDEX llm_usage_workspace_created_idx (workspace_id, created_at), INDEX llm_usage_feature_idx (feature), INDEX llm_usage_created_idx (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE llm_usage_logs ADD CONSTRAINT FK_6CEFC5382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE llm_usage_logs DROP FOREIGN KEY FK_6CEFC5382D40A1F');
        $this->addSql('DROP TABLE llm_usage_logs');
    }
}
