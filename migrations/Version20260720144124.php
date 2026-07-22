<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inbound mute rules + Conversation.mutedAt — suppress a KIND of inbound message
 * (e.g. 2FA/verification noise) by flagging matching conversations as muted
 * (kept + searchable, out of the default inbox), driven by workspace rules.
 *
 * Hand-trimmed to only these two changes (the auto-diff also picked up unrelated
 * numeric-DEFAULT drift on other tables, which was left out).
 */
final class Version20260720144124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add inbound_mute_rules table + conversations.muted_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbound_mute_rules (match_type VARCHAR(20) NOT NULL, value VARCHAR(250) NOT NULL, is_enabled TINYINT NOT NULL, match_count INT DEFAULT 0 NOT NULL, last_matched_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_6557CAD07D182D95 (created_by_user_id), INDEX IDX_6557CAD02793CC5E (updated_by_user_id), INDEX IDX_6557CAD082D40A1F (workspace_id), INDEX inbound_mute_rule_workspace_idx (workspace_id, is_enabled), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE inbound_mute_rules ADD CONSTRAINT FK_6557CAD07D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_mute_rules ADD CONSTRAINT FK_6557CAD02793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_mute_rules ADD CONSTRAINT FK_6557CAD082D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversations ADD muted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbound_mute_rules DROP FOREIGN KEY FK_6557CAD07D182D95');
        $this->addSql('ALTER TABLE inbound_mute_rules DROP FOREIGN KEY FK_6557CAD02793CC5E');
        $this->addSql('ALTER TABLE inbound_mute_rules DROP FOREIGN KEY FK_6557CAD082D40A1F');
        $this->addSql('DROP TABLE inbound_mute_rules');
        $this->addSql('ALTER TABLE conversations DROP muted_at');
    }
}
