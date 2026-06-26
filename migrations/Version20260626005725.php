<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626005725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conversation threading: conversation_notes + saved_replies tables, outbound_messages.kind (Phase C Schicht 2)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE conversation_notes (body LONGTEXT NOT NULL, is_pinned TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, conversation_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_15CD85A07D182D95 (created_by_user_id), INDEX IDX_15CD85A02793CC5E (updated_by_user_id), INDEX conversation_note_conversation_idx (conversation_id), INDEX conversation_note_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE saved_replies (name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, shortcut VARCHAR(60) DEFAULT NULL, body LONGTEXT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_2A01A8BD7D182D95 (created_by_user_id), INDEX IDX_2A01A8BD2793CC5E (updated_by_user_id), INDEX saved_reply_workspace_idx (workspace_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE conversation_notes ADD CONSTRAINT FK_15CD85A09AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_notes ADD CONSTRAINT FK_15CD85A082D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_notes ADD CONSTRAINT FK_15CD85A07D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversation_notes ADD CONSTRAINT FK_15CD85A02793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE saved_replies ADD CONSTRAINT FK_2A01A8BD82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE saved_replies ADD CONSTRAINT FK_2A01A8BD7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE saved_replies ADD CONSTRAINT FK_2A01A8BD2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE outbound_messages ADD kind VARCHAR(8) DEFAULT \'reply\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation_notes DROP FOREIGN KEY FK_15CD85A09AC0396');
        $this->addSql('ALTER TABLE conversation_notes DROP FOREIGN KEY FK_15CD85A082D40A1F');
        $this->addSql('ALTER TABLE conversation_notes DROP FOREIGN KEY FK_15CD85A07D182D95');
        $this->addSql('ALTER TABLE conversation_notes DROP FOREIGN KEY FK_15CD85A02793CC5E');
        $this->addSql('ALTER TABLE saved_replies DROP FOREIGN KEY FK_2A01A8BD82D40A1F');
        $this->addSql('ALTER TABLE saved_replies DROP FOREIGN KEY FK_2A01A8BD7D182D95');
        $this->addSql('ALTER TABLE saved_replies DROP FOREIGN KEY FK_2A01A8BD2793CC5E');
        $this->addSql('DROP TABLE conversation_notes');
        $this->addSql('DROP TABLE saved_replies');
        $this->addSql('ALTER TABLE outbound_messages DROP kind');
    }
}
