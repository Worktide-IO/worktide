<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Tracker entity (Bug/Feature/Story/Support classification) +
 * Task.tracker FK + seed the four canonical trackers into every
 * existing workspace so the SPA can show issue-type chips from day 1.
 */
final class Version20260616201034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Tracker entity + Task.tracker FK + seed canonical trackers per workspace';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE trackers (name VARCHAR(60) NOT NULL, icon VARCHAR(40) NOT NULL, color VARCHAR(16) NOT NULL, position INT NOT NULL, is_default TINYINT NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, default_status_id BINARY(16) DEFAULT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_5DCB1258FA95281A (default_status_id), INDEX IDX_5DCB12587D182D95 (created_by_user_id), INDEX IDX_5DCB12582793CC5E (updated_by_user_id), INDEX tracker_workspace_idx (workspace_id), UNIQUE INDEX tracker_workspace_name_unique (workspace_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE trackers ADD CONSTRAINT FK_5DCB1258FA95281A FOREIGN KEY (default_status_id) REFERENCES task_statuses (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE trackers ADD CONSTRAINT FK_5DCB125882D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trackers ADD CONSTRAINT FK_5DCB12587D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE trackers ADD CONSTRAINT FK_5DCB12582793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tasks ADD tracker_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE tasks ADD CONSTRAINT FK_50586597FB5230B FOREIGN KEY (tracker_id) REFERENCES trackers (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX IDX_50586597FB5230B ON tasks (tracker_id)');

        // Seed canonical trackers for every existing workspace. Using
        // UUID() avoids touching PHP — the DB generates v4 UUIDs that
        // EntityIdTrait accepts on read (no v7 monotonicity needed for
        // bulk-seeded config rows).
        $seed = function (string $name, string $icon, string $color, int $position, int $isDefault): string {
            return "INSERT INTO trackers (id, workspace_id, name, icon, color, position, is_default, created_at, updated_at, version)
                    SELECT UNHEX(REPLACE(UUID(), '-', '')), w.id, '$name', '$icon', '$color', $position, $isDefault, NOW(), NOW(), 1
                    FROM workspaces w";
        };
        $this->addSql($seed('Bug',     'bug',         '#ef4444', 1, 0));
        $this->addSql($seed('Feature', 'sparkles',    '#3b82f6', 2, 1)); // default
        $this->addSql($seed('Story',   'book-open',   '#10b981', 3, 0));
        $this->addSql($seed('Support', 'life-buoy',   '#f59e0b', 4, 0));
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trackers DROP FOREIGN KEY FK_5DCB1258FA95281A');
        $this->addSql('ALTER TABLE trackers DROP FOREIGN KEY FK_5DCB125882D40A1F');
        $this->addSql('ALTER TABLE trackers DROP FOREIGN KEY FK_5DCB12587D182D95');
        $this->addSql('ALTER TABLE trackers DROP FOREIGN KEY FK_5DCB12582793CC5E');
        $this->addSql('DROP TABLE trackers');
        $this->addSql('ALTER TABLE tasks DROP FOREIGN KEY FK_50586597FB5230B');
        $this->addSql('DROP INDEX IDX_50586597FB5230B ON tasks');
        $this->addSql('ALTER TABLE tasks DROP tracker_id');
    }
}
