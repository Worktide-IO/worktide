<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704204711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Research agent: research_missions, research_mission_messages, leads, lead_activities';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE lead_activities (type VARCHAR(16) NOT NULL, channel VARCHAR(16) DEFAULT NULL, payload JSON DEFAULT NULL, occurred_at DATETIME NOT NULL, outcome VARCHAR(255) DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, lead_id BINARY(16) NOT NULL, actor_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_9D6AB47910DAF24A (actor_id), INDEX IDX_9D6AB47982D40A1F (workspace_id), INDEX lead_activity_lead_idx (lead_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE leads (is_company TINYINT DEFAULT 1 NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(64) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, role VARCHAR(160) DEFAULT NULL, industry VARCHAR(120) DEFAULT NULL, region VARCHAR(120) DEFAULT NULL, source VARCHAR(16) NOT NULL, source_url VARCHAR(1024) DEFAULT NULL, source_detail JSON DEFAULT NULL, fit_score INT DEFAULT NULL, score_reason LONGTEXT DEFAULT NULL, stage VARCHAR(16) NOT NULL, dedupe_key VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, mission_id BINARY(16) DEFAULT NULL, converted_customer_id BINARY(16) DEFAULT NULL, assigned_to_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_1790455211297FC3 (converted_customer_id), INDEX IDX_17904552F4BD7827 (assigned_to_id), INDEX IDX_1790455282D40A1F (workspace_id), INDEX IDX_179045527D182D95 (created_by_user_id), INDEX IDX_179045522793CC5E (updated_by_user_id), INDEX lead_workspace_stage_idx (workspace_id, stage), INDEX lead_mission_idx (mission_id), INDEX lead_dedupe_idx (workspace_id, dedupe_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE research_mission_messages (role VARCHAR(8) NOT NULL, content LONGTEXT NOT NULL, question JSON DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, mission_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_111BEEDF82D40A1F (workspace_id), INDEX research_mission_message_mission_idx (mission_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE research_missions (prompt LONGTEXT NOT NULL, objective VARCHAR(20) NOT NULL, created_via VARCHAR(16) NOT NULL, brief JSON DEFAULT NULL, status VARCHAR(16) NOT NULL, state JSON DEFAULT NULL, found_count INT DEFAULT 0 NOT NULL, target_count INT DEFAULT NULL, summary LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_C90A83B482D40A1F (workspace_id), INDEX IDX_C90A83B47D182D95 (created_by_user_id), INDEX IDX_C90A83B42793CC5E (updated_by_user_id), INDEX research_mission_workspace_status_idx (workspace_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lead_activities ADD CONSTRAINT FK_9D6AB47955458D FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lead_activities ADD CONSTRAINT FK_9D6AB47910DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE lead_activities ADD CONSTRAINT FK_9D6AB47982D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904552BE6CAE90 FOREIGN KEY (mission_id) REFERENCES research_missions (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_1790455211297FC3 FOREIGN KEY (converted_customer_id) REFERENCES customers (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_17904552F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_1790455282D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_179045527D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE leads ADD CONSTRAINT FK_179045522793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE research_mission_messages ADD CONSTRAINT FK_111BEEDFBE6CAE90 FOREIGN KEY (mission_id) REFERENCES research_missions (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE research_mission_messages ADD CONSTRAINT FK_111BEEDF82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE research_missions ADD CONSTRAINT FK_C90A83B482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE research_missions ADD CONSTRAINT FK_C90A83B47D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE research_missions ADD CONSTRAINT FK_C90A83B42793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lead_activities DROP FOREIGN KEY FK_9D6AB47955458D');
        $this->addSql('ALTER TABLE lead_activities DROP FOREIGN KEY FK_9D6AB47910DAF24A');
        $this->addSql('ALTER TABLE lead_activities DROP FOREIGN KEY FK_9D6AB47982D40A1F');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_17904552BE6CAE90');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_1790455211297FC3');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_17904552F4BD7827');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_1790455282D40A1F');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_179045527D182D95');
        $this->addSql('ALTER TABLE leads DROP FOREIGN KEY FK_179045522793CC5E');
        $this->addSql('ALTER TABLE research_mission_messages DROP FOREIGN KEY FK_111BEEDFBE6CAE90');
        $this->addSql('ALTER TABLE research_mission_messages DROP FOREIGN KEY FK_111BEEDF82D40A1F');
        $this->addSql('ALTER TABLE research_missions DROP FOREIGN KEY FK_C90A83B482D40A1F');
        $this->addSql('ALTER TABLE research_missions DROP FOREIGN KEY FK_C90A83B47D182D95');
        $this->addSql('ALTER TABLE research_missions DROP FOREIGN KEY FK_C90A83B42793CC5E');
        $this->addSql('DROP TABLE lead_activities');
        $this->addSql('DROP TABLE leads');
        $this->addSql('DROP TABLE research_mission_messages');
        $this->addSql('DROP TABLE research_missions');
    }
}
