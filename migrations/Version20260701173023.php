<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701173023 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ai_recommendations table (human-in-the-loop AI ticket suggestions).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_recommendations (target VARCHAR(16) NOT NULL, target_id BINARY(16) NOT NULL, kind VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, suggestion JSON NOT NULL, reasoning LONGTEXT DEFAULT NULL, model VARCHAR(80) DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, reviewed_by_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_9F218041FC6B21F1 (reviewed_by_id), INDEX IDX_9F21804182D40A1F (workspace_id), INDEX ai_reco_target_idx (target, target_id), INDEX ai_reco_workspace_status_idx (workspace_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_recommendations ADD CONSTRAINT FK_9F218041FC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ai_recommendations ADD CONSTRAINT FK_9F21804182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_recommendations DROP FOREIGN KEY FK_9F218041FC6B21F1');
        $this->addSql('ALTER TABLE ai_recommendations DROP FOREIGN KEY FK_9F21804182D40A1F');
        $this->addSql('DROP TABLE ai_recommendations');
    }
}
