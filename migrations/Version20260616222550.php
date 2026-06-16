<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260616222550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE channels (name VARCHAR(80) NOT NULL, adapter_code VARCHAR(40) NOT NULL, capabilities JSON NOT NULL, address VARCHAR(200) DEFAULT NULL, inbound_config JSON NOT NULL, outbound_config JSON NOT NULL, auth_config JSON NOT NULL, is_shared TINYINT NOT NULL, is_enabled TINYINT NOT NULL, last_synced_at DATETIME DEFAULT NULL, last_sync_error LONGTEXT DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_F314E2B67D182D95 (created_by_user_id), INDEX IDX_F314E2B62793CC5E (updated_by_user_id), INDEX channel_workspace_idx (workspace_id), INDEX channel_adapter_idx (adapter_code), UNIQUE INDEX channel_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversations (subject VARCHAR(250) NOT NULL, thread_key VARCHAR(250) NOT NULL, status VARCHAR(12) DEFAULT \'open\' NOT NULL, sender_raw VARCHAR(200) DEFAULT NULL, last_event_at DATETIME NOT NULL, participant_iris JSON NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, channel_id BINARY(16) NOT NULL, customer_id BINARY(16) DEFAULT NULL, assignee_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_C2521BF172F5A1AA (channel_id), INDEX IDX_C2521BF17D182D95 (created_by_user_id), INDEX IDX_C2521BF12793CC5E (updated_by_user_id), INDEX conversation_workspace_idx (workspace_id), INDEX conversation_status_idx (workspace_id, status), INDEX conversation_customer_idx (customer_id), INDEX conversation_assignee_idx (assignee_id), UNIQUE INDEX conversation_channel_thread_unique (channel_id, thread_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE inbound_events (external_id VARCHAR(250) NOT NULL, sender_raw VARCHAR(200) DEFAULT NULL, subject VARCHAR(250) DEFAULT NULL, body LONGTEXT DEFAULT NULL, attachments JSON NOT NULL, source_metadata JSON NOT NULL, trace_url VARCHAR(500) DEFAULT NULL, state VARCHAR(12) DEFAULT \'pending\' NOT NULL, received_at DATETIME NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, channel_id BINARY(16) NOT NULL, sender_contact_id BINARY(16) DEFAULT NULL, conversation_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_E7D89A3572F5A1AA (channel_id), INDEX IDX_E7D89A35B3257B87 (sender_contact_id), INDEX IDX_E7D89A357D182D95 (created_by_user_id), INDEX IDX_E7D89A352793CC5E (updated_by_user_id), INDEX inbound_event_workspace_idx (workspace_id), INDEX inbound_event_conversation_idx (conversation_id), INDEX inbound_event_state_idx (workspace_id, state), INDEX inbound_event_received_idx (received_at), UNIQUE INDEX inbound_event_channel_external_unique (channel_id, external_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE outbound_messages (recipient_raw VARCHAR(200) NOT NULL, additional_recipients JSON NOT NULL, subject VARCHAR(250) DEFAULT NULL, body LONGTEXT NOT NULL, attachments JSON NOT NULL, created_by_recommendation_id BINARY(16) DEFAULT NULL, status VARCHAR(12) DEFAULT \'queued\' NOT NULL, status_reason LONGTEXT DEFAULT NULL, attempt_count INT NOT NULL, sent_at DATETIME DEFAULT NULL, external_id VARCHAR(250) DEFAULT NULL, opened_at DATETIME DEFAULT NULL, clicked_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, channel_id BINARY(16) NOT NULL, recipient_contact_id BINARY(16) DEFAULT NULL, conversation_id BINARY(16) DEFAULT NULL, in_reply_to_inbound_event_id BINARY(16) DEFAULT NULL, created_by_user_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_642BA0C19FA16F34 (recipient_contact_id), INDEX IDX_642BA0C1E1916EB4 (in_reply_to_inbound_event_id), INDEX IDX_642BA0C17D182D95 (created_by_user_id), INDEX IDX_642BA0C12793CC5E (updated_by_user_id), INDEX outbound_workspace_idx (workspace_id), INDEX outbound_channel_idx (channel_id), INDEX outbound_conversation_idx (conversation_id), INDEX outbound_status_idx (workspace_id, status), INDEX outbound_queued_idx (status, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE channels ADD CONSTRAINT FK_F314E2B682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE channels ADD CONSTRAINT FK_F314E2B67D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE channels ADD CONSTRAINT FK_F314E2B62793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF172F5A1AA FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF19395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF159EC7D60 FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF17D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF12793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_events ADD CONSTRAINT FK_E7D89A3572F5A1AA FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inbound_events ADD CONSTRAINT FK_E7D89A35B3257B87 FOREIGN KEY (sender_contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_events ADD CONSTRAINT FK_E7D89A359AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_events ADD CONSTRAINT FK_E7D89A3582D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inbound_events ADD CONSTRAINT FK_E7D89A357D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inbound_events ADD CONSTRAINT FK_E7D89A352793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C172F5A1AA FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C19FA16F34 FOREIGN KEY (recipient_contact_id) REFERENCES contacts (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C19AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C1E1916EB4 FOREIGN KEY (in_reply_to_inbound_event_id) REFERENCES inbound_events (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C17D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C182D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE outbound_messages ADD CONSTRAINT FK_642BA0C12793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE channels DROP FOREIGN KEY FK_F314E2B682D40A1F');
        $this->addSql('ALTER TABLE channels DROP FOREIGN KEY FK_F314E2B67D182D95');
        $this->addSql('ALTER TABLE channels DROP FOREIGN KEY FK_F314E2B62793CC5E');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF172F5A1AA');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF19395C3F3');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF159EC7D60');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF182D40A1F');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF17D182D95');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF12793CC5E');
        $this->addSql('ALTER TABLE inbound_events DROP FOREIGN KEY FK_E7D89A3572F5A1AA');
        $this->addSql('ALTER TABLE inbound_events DROP FOREIGN KEY FK_E7D89A35B3257B87');
        $this->addSql('ALTER TABLE inbound_events DROP FOREIGN KEY FK_E7D89A359AC0396');
        $this->addSql('ALTER TABLE inbound_events DROP FOREIGN KEY FK_E7D89A3582D40A1F');
        $this->addSql('ALTER TABLE inbound_events DROP FOREIGN KEY FK_E7D89A357D182D95');
        $this->addSql('ALTER TABLE inbound_events DROP FOREIGN KEY FK_E7D89A352793CC5E');
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_642BA0C172F5A1AA');
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_642BA0C19FA16F34');
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_642BA0C19AC0396');
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_642BA0C1E1916EB4');
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_642BA0C17D182D95');
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_642BA0C182D40A1F');
        $this->addSql('ALTER TABLE outbound_messages DROP FOREIGN KEY FK_642BA0C12793CC5E');
        $this->addSql('DROP TABLE channels');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('DROP TABLE inbound_events');
        $this->addSql('DROP TABLE outbound_messages');
    }
}
