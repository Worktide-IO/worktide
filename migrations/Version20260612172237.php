<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612172237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE domain_events (name VARCHAR(80) NOT NULL, aggregate_type VARCHAR(80) NOT NULL, aggregate_id BINARY(16) DEFAULT NULL, payload JSON NOT NULL, occurred_at DATETIME NOT NULL, id BINARY(16) NOT NULL, workspace_id BINARY(16) DEFAULT NULL, actor_id BINARY(16) DEFAULT NULL, INDEX IDX_3CE45B8310DAF24A (actor_id), INDEX domain_event_name_idx (name), INDEX domain_event_aggregate_idx (aggregate_type, aggregate_id), INDEX domain_event_workspace_idx (workspace_id), INDEX domain_event_occurred_idx (occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE domain_events ADD CONSTRAINT FK_3CE45B8382D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE domain_events ADD CONSTRAINT FK_3CE45B8310DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domain_events DROP FOREIGN KEY FK_3CE45B8382D40A1F');
        $this->addSql('ALTER TABLE domain_events DROP FOREIGN KEY FK_3CE45B8310DAF24A');
        $this->addSql('DROP TABLE domain_events');
    }
}
