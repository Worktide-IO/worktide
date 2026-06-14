<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614002914 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B11 — RolePermissionOverride for workspace-scoped permission matrix tweaks';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE role_permission_overrides (role VARCHAR(16) NOT NULL, capability VARCHAR(80) NOT NULL, is_granted TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_AEEDB6E7D182D95 (created_by_user_id), INDEX IDX_AEEDB6E2793CC5E (updated_by_user_id), INDEX role_permission_override_workspace_idx (workspace_id), UNIQUE INDEX role_permission_override_unique (workspace_id, role, capability), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE role_permission_overrides ADD CONSTRAINT FK_AEEDB6E82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permission_overrides ADD CONSTRAINT FK_AEEDB6E7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE role_permission_overrides ADD CONSTRAINT FK_AEEDB6E2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE role_permission_overrides DROP FOREIGN KEY FK_AEEDB6E82D40A1F');
        $this->addSql('ALTER TABLE role_permission_overrides DROP FOREIGN KEY FK_AEEDB6E7D182D95');
        $this->addSql('ALTER TABLE role_permission_overrides DROP FOREIGN KEY FK_AEEDB6E2793CC5E');
        $this->addSql('DROP TABLE role_permission_overrides');
    }
}
