<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709134929 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notifications table (per-user inbox, fed from DomainEventLog).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notifications (type VARCHAR(32) NOT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT DEFAULT NULL, link VARCHAR(512) NOT NULL, source_event_id BINARY(16) DEFAULT NULL, occurred_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, recipient_id BINARY(16) NOT NULL, workspace_id BINARY(16) DEFAULT NULL, actor_id BINARY(16) DEFAULT NULL, INDEX IDX_6000B0D3E92F8F78 (recipient_id), INDEX IDX_6000B0D382D40A1F (workspace_id), INDEX IDX_6000B0D310DAF24A (actor_id), INDEX notification_recipient_read_idx (recipient_id, read_at), INDEX notification_recipient_id_idx (recipient_id, id), UNIQUE INDEX notification_dedupe (recipient_id, source_event_id, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3E92F8F78 FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D310DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3E92F8F78');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D382D40A1F');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D310DAF24A');
        $this->addSql('DROP TABLE notifications');
    }
}
