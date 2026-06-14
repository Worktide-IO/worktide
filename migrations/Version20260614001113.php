<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614001113 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B10 — Webhooks + WebhookDeliveries';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE webhook_deliveries (event_id BINARY(16) DEFAULT NULL, event_name VARCHAR(120) NOT NULL, status VARCHAR(16) NOT NULL, http_status INT DEFAULT NULL, response_body LONGTEXT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, attempt INT NOT NULL, duration_ms INT DEFAULT NULL, attempted_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, webhook_id BINARY(16) NOT NULL, INDEX webhook_delivery_webhook_idx (webhook_id), INDEX webhook_delivery_status_idx (status), INDEX webhook_delivery_event_idx (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webhooks (name VARCHAR(200) NOT NULL, url VARCHAR(2048) NOT NULL, secret VARCHAR(200) NOT NULL, event_types JSON NOT NULL, is_active TINYINT NOT NULL, failure_count INT NOT NULL, last_triggered_at DATETIME DEFAULT NULL, last_succeeded_at DATETIME DEFAULT NULL, last_failed_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_998C4FDD82D40A1F (workspace_id), INDEX IDX_998C4FDD7D182D95 (created_by_user_id), INDEX IDX_998C4FDD2793CC5E (updated_by_user_id), INDEX webhook_workspace_active_idx (workspace_id, is_active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE webhook_deliveries ADD CONSTRAINT FK_3681F32D5C9BA60B FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhooks ADD CONSTRAINT FK_998C4FDD82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhooks ADD CONSTRAINT FK_998C4FDD7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE webhooks ADD CONSTRAINT FK_998C4FDD2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE webhook_deliveries DROP FOREIGN KEY FK_3681F32D5C9BA60B');
        $this->addSql('ALTER TABLE webhooks DROP FOREIGN KEY FK_998C4FDD82D40A1F');
        $this->addSql('ALTER TABLE webhooks DROP FOREIGN KEY FK_998C4FDD7D182D95');
        $this->addSql('ALTER TABLE webhooks DROP FOREIGN KEY FK_998C4FDD2793CC5E');
        $this->addSql('DROP TABLE webhook_deliveries');
        $this->addSql('DROP TABLE webhooks');
    }
}
