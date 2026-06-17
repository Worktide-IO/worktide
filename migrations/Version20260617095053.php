<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617095053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE entity_change_outbox (entity_type VARCHAR(40) NOT NULL, entity_id BINARY(16) NOT NULL, changed_fields JSON NOT NULL, previous_values JSON NOT NULL, is_delete TINYINT NOT NULL, status VARCHAR(16) DEFAULT \'pending\' NOT NULL, attempt_count INT NOT NULL, next_attempt_at DATETIME NOT NULL, last_error LONGTEXT DEFAULT NULL, processed_at DATETIME DEFAULT NULL, per_mapping_state JSON NOT NULL, id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workspace_id BINARY(16) NOT NULL, created_by_user_id BINARY(16) DEFAULT NULL, updated_by_user_id BINARY(16) DEFAULT NULL, INDEX IDX_437D94EC7D182D95 (created_by_user_id), INDEX IDX_437D94EC2793CC5E (updated_by_user_id), INDEX entity_outbox_workspace_idx (workspace_id), INDEX entity_outbox_status_idx (status), INDEX entity_outbox_claimable_idx (status, next_attempt_at), INDEX entity_outbox_entity_idx (entity_type, entity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entity_change_outbox ADD CONSTRAINT FK_437D94EC82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entity_change_outbox ADD CONSTRAINT FK_437D94EC7D182D95 FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE entity_change_outbox ADD CONSTRAINT FK_437D94EC2793CC5E FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE entity_change_outbox DROP FOREIGN KEY FK_437D94EC82D40A1F');
        $this->addSql('ALTER TABLE entity_change_outbox DROP FOREIGN KEY FK_437D94EC7D182D95');
        $this->addSql('ALTER TABLE entity_change_outbox DROP FOREIGN KEY FK_437D94EC2793CC5E');
        $this->addSql('DROP TABLE entity_change_outbox');
    }
}
