<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715004917 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add magic_login_tokens (single-use passwordless portal login / staff impersonation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE magic_login_tokens (token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, issued_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_8F61CB08B82713E2 (issued_by_user_id), INDEX magic_login_user_idx (user_id), UNIQUE INDEX magic_login_token_hash_unique (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE magic_login_tokens ADD CONSTRAINT FK_8F61CB08A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE magic_login_tokens ADD CONSTRAINT FK_8F61CB08B82713E2 FOREIGN KEY (issued_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE magic_login_tokens DROP FOREIGN KEY FK_8F61CB08A76ED395');
        $this->addSql('ALTER TABLE magic_login_tokens DROP FOREIGN KEY FK_8F61CB08B82713E2');
        $this->addSql('DROP TABLE magic_login_tokens');
    }
}
