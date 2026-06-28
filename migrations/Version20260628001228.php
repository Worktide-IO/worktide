<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260628001228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE social_post_targets (body_override LONGTEXT DEFAULT NULL, status VARCHAR(12) DEFAULT \'queued\' NOT NULL, external_id VARCHAR(250) DEFAULT NULL, permalink VARCHAR(500) DEFAULT NULL, error_reason LONGTEXT DEFAULT NULL, attempt_count INT NOT NULL, published_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, social_post_id BINARY(16) NOT NULL, channel_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, INDEX IDX_4E08001F72F5A1AA (channel_id), INDEX IDX_4E08001F82D40A1F (workspace_id), INDEX social_target_post_idx (social_post_id), INDEX social_target_status_idx (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE social_posts (body LONGTEXT NOT NULL, media_refs JSON NOT NULL, status VARCHAR(16) DEFAULT \'draft\' NOT NULL, scheduled_at DATETIME DEFAULT NULL, approved_at DATETIME DEFAULT NULL, published_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, approved_by_user_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_C2CD0E687BBF3A5D (approved_by_user_id), INDEX IDX_C2CD0E687D182D95 (created_by_user_id), INDEX IDX_C2CD0E682793CC5E (updated_by_user_id), INDEX social_post_workspace_idx (workspace_id), INDEX social_post_status_idx (status), INDEX social_post_due_idx (status, scheduled_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE social_post_targets ADD CONSTRAINT FK_4E08001FC4F2D6B1 FOREIGN KEY (social_post_id) REFERENCES social_posts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_post_targets ADD CONSTRAINT FK_4E08001F72F5A1AA FOREIGN KEY (channel_id) REFERENCES channels (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_post_targets ADD CONSTRAINT FK_4E08001F82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_posts ADD CONSTRAINT FK_C2CD0E687BBF3A5D FOREIGN KEY (approved_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE social_posts ADD CONSTRAINT FK_C2CD0E6882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE social_posts ADD CONSTRAINT FK_C2CD0E687D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE social_posts ADD CONSTRAINT FK_C2CD0E682793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE social_post_targets DROP FOREIGN KEY FK_4E08001FC4F2D6B1');
        $this->addSql('ALTER TABLE social_post_targets DROP FOREIGN KEY FK_4E08001F72F5A1AA');
        $this->addSql('ALTER TABLE social_post_targets DROP FOREIGN KEY FK_4E08001F82D40A1F');
        $this->addSql('ALTER TABLE social_posts DROP FOREIGN KEY FK_C2CD0E687BBF3A5D');
        $this->addSql('ALTER TABLE social_posts DROP FOREIGN KEY FK_C2CD0E6882D40A1F');
        $this->addSql('ALTER TABLE social_posts DROP FOREIGN KEY FK_C2CD0E687D182D95');
        $this->addSql('ALTER TABLE social_posts DROP FOREIGN KEY FK_C2CD0E682793CC5E');
        $this->addSql('DROP TABLE social_post_targets');
        $this->addSql('DROP TABLE social_posts');
    }
}
