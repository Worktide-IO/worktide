<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614092547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sweep — PersonalAccessToken for external API auth';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE personal_access_tokens (name VARCHAR(120) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_prefix VARCHAR(16) NOT NULL, scopes JSON NOT NULL, last_used_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_E63C21667D182D95 (created_by_user_id), INDEX IDX_E63C21662793CC5E (updated_by_user_id), INDEX pat_owner_idx (owner_id), INDEX pat_workspace_idx (workspace_id), UNIQUE INDEX pat_token_hash_unique (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE personal_access_tokens ADD CONSTRAINT FK_E63C21667E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE personal_access_tokens ADD CONSTRAINT FK_E63C216682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE personal_access_tokens ADD CONSTRAINT FK_E63C21667D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE personal_access_tokens ADD CONSTRAINT FK_E63C21662793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE personal_access_tokens DROP FOREIGN KEY FK_E63C21667E3C61F9');
        $this->addSql('ALTER TABLE personal_access_tokens DROP FOREIGN KEY FK_E63C216682D40A1F');
        $this->addSql('ALTER TABLE personal_access_tokens DROP FOREIGN KEY FK_E63C21667D182D95');
        $this->addSql('ALTER TABLE personal_access_tokens DROP FOREIGN KEY FK_E63C21662793CC5E');
        $this->addSql('DROP TABLE personal_access_tokens');
    }
}
