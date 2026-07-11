<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User chat webhooks — a user's personal Slack/Mattermost/Teams incoming-webhook
 * URL for notification delivery (one per user; URL encrypted at rest).
 */
final class Version20260711200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User chat webhooks (Slack/Mattermost/Teams notification delivery)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_chat_webhooks (provider VARCHAR(16) NOT NULL, url LONGTEXT NOT NULL, enabled TINYINT(1) NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX user_chat_webhook_user_uniq (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_chat_webhooks ADD CONSTRAINT FK_user_chat_webhook_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_chat_webhooks DROP FOREIGN KEY FK_user_chat_webhook_user');
        $this->addSql('DROP TABLE user_chat_webhooks');
    }
}
